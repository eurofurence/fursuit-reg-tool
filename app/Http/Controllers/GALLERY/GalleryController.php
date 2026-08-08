<?php

namespace App\Http\Controllers\GALLERY;

use App\Domain\CatchEmAll\Models\UserCatch;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class GalleryController extends Controller
{
    const ITEMS_PER_LOAD = 20; // 20 items per infinite scroll load

    /** How long the folder overview (counts and covers) is reused for. */
    const FOLDER_CACHE_MINUTES = 10;

    /** FursuitObserver drops this whenever a fursuit changes what a folder card shows. */
    const FOLDER_CACHE_KEY = 'gallery:folders';

    /** Per-event leaderboard cache; the event id is appended. */
    const RANKING_CACHE_KEY = 'gallery:ranking:';

    /** Short, because during the convention the standings move all day. */
    const RANKING_CACHE_MINUTES = 5;

    /**
     * The landing page: one folder card per event, plus an "everything" card.
     *
     * The grid itself lives behind a folder now, so the first thing a visitor picks is a
     * convention year rather than scrolling a decade of fursuits from the top.
     */
    public function folders(Request $request): Response|RedirectResponse
    {
        // Old links (and bookmarks) carry their selection in the query string; send them
        // straight into the folder they meant.
        if ($request->filled('event')) {
            return redirect()->route('gallery.event', [
                'event' => $request->input('event'),
                ...$request->only(['query', 'species', 'sort']),
            ]);
        }

        if ($request->filled('query') || $request->filled('species') || $request->filled('sort')) {
            return redirect()->route('gallery.all', $request->only(['query', 'species', 'sort']));
        }

        $folders = Cache::remember(
            self::FOLDER_CACHE_KEY,
            now()->addMinutes(self::FOLDER_CACHE_MINUTES),
            fn () => $this->buildFolders()
        );

        return Inertia::render('Gallery/GalleryFolders', [
            // Signing happens outside the cache so a cached folder never hands out an
            // expired link.
            'folders' => collect($folders['events'])->map(fn ($folder) => [
                ...$folder,
                'cover' => Fursuit::variantUrl($folder['cover']),
            ])->values(),
            'total' => [
                'fursuits' => $folders['total_fursuits'],
                'cover' => Fursuit::variantUrl($folders['total_cover']),
            ],
            ...$this->rankingFor(null),
        ]);
    }

    /**
     * The grid, scoped to one folder. `$event` comes from the route; the query string is
     * still honoured so the load-more endpoint and old links keep working.
     */
    public function index(Request $request, ?Event $event = null): Response|RedirectResponse
    {
        $searchTerm = $this->textInput($request, 'query');
        $speciesFilter = $this->textInput($request, 'species');

        $selectedEvent = $event ?? $this->eventFromRequest($request);
        $eventFilter = $selectedEvent?->id;

        $isHistoricalEvent = $selectedEvent ? ! $selectedEvent->catch_em_all_enabled : false;
        $sortBy = $this->textInput($request, 'sort', $isHistoricalEvent ? 'name_asc' : 'catches_desc');
        $offset = max(0, intval($request->input('offset', 0)));

        // Build base query
        $query = $this->publishedFursuits()->with(['species', 'event']);

        if ($eventFilter) {
            $query->where('event_id', $eventFilter);
        }

        $totalFursuitCount = $query->count(); // Total for this event filter, before the search

        $this->applyFilters($query, $searchTerm, $speciesFilter);

        // How many are left once the search and species filters are applied
        $totalResultCount = $query->count();

        // Move duplicated sort to func
        $this->applyGallerySorting($query, $sortBy, $isHistoricalEvent);

        $fursuits = $query->offset($offset)
            ->limit(self::ITEMS_PER_LOAD)
            ->get();

        $hasMore = ($offset + self::ITEMS_PER_LOAD) < $totalResultCount;

        // Get all species for filter dropdown (only those used 10+ times)
        $allSpecies = Species::query()
            ->whereHas('fursuits', function ($q) {
                $q->where('status', 'approved')->where('published', true);
            }, '>=', 10)
            ->orderBy('name')
            ->get();

        // Get all events that have published fursuits
        $allEvents = Event::query()
            ->whereHas('fursuits', function ($q) {
                $q->where('status', 'approved')->where('published', true);
            })
            ->orderBy('starts_at', 'desc')
            ->get();

        return Inertia::render('Gallery/GalleryIndex', [
            'fursuits' => $this->presentFursuits($fursuits, $isHistoricalEvent),
            'has_more' => $hasMore,
            'totalResult' => $totalResultCount,
            'totalFursuit' => $totalFursuitCount,
            'is_historical_event' => $isHistoricalEvent,
            'selected_event' => $selectedEvent ? [
                'id' => $selectedEvent->id,
                'name' => $selectedEvent->name,
                'archival_notice' => $selectedEvent->archival_notice,
                'catch_em_all_enabled' => $selectedEvent->catch_em_all_enabled,
            ] : null,
            ...$this->rankingFor($selectedEvent),
            'filters' => [
                'search' => $searchTerm,
                'species' => $speciesFilter,
                'event' => $eventFilter ? (string) $eventFilter : '',
                'sort' => $sortBy,
            ],
            'species_options' => $allSpecies->map(function ($species) {
                return [
                    'value' => $species->name,
                    'label' => $species->name,
                ];
            }),
            'event_options' => $allEvents->map(function ($event) {
                return [
                    'value' => $event->id,
                    'label' => $event->name,
                ];
            }),
        ]);
    }

    public function loadMore(Request $request): JsonResponse
    {
        $searchTerm = $this->textInput($request, 'query');
        $speciesFilter = $this->textInput($request, 'species');

        $selectedEvent = $this->eventFromRequest($request);
        $isHistoricalEvent = $selectedEvent ? ! $selectedEvent->catch_em_all_enabled : false;
        $sortBy = $this->textInput($request, 'sort', $isHistoricalEvent ? 'name_asc' : 'catches_desc');
        $offset = max(0, intval($request->input('offset', 0)));

        $query = $this->publishedFursuits()->with(['species', 'event']);

        if ($selectedEvent) {
            $query->where('event_id', $selectedEvent->id);
        }

        $this->applyFilters($query, $searchTerm, $speciesFilter);

        // Get total count
        $totalCount = $query->count();

        // Move duplicated sort to func
        $this->applyGallerySorting($query, $sortBy, $isHistoricalEvent);

        $fursuits = $query->offset($offset)
            ->limit(self::ITEMS_PER_LOAD)
            ->get();

        return response()->json([
            'fursuits' => $this->presentFursuits($fursuits, $isHistoricalEvent),
            'has_more' => ($offset + self::ITEMS_PER_LOAD) < $totalCount,
            'total' => $totalCount,
        ]);
    }

    public function getTotalFursuitCount(Request $request): JsonResponse
    {
        $count = Fursuit::query()
            ->where('status', 'approved')
            ->where('published', true)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Everything the gallery is allowed to show.
     *
     * A rendered thumbnail is part of the entry requirement. The gallery has no business
     * serving the print-quality master - those run past a megabyte at 2040x2720 - so a
     * fursuit whose variants have not been rendered yet simply is not listed. It appears
     * the moment GenerateFursuitWebpJob fills the columns in.
     */
    private function publishedFursuits(): Builder
    {
        return Fursuit::query()
            ->where('status', 'approved')
            ->whereNotNull('image')
            ->whereNotNull('image_thumb')
            ->where('published', true);
    }

    /**
     * A filter value as a plain string.
     *
     * `$request->input($key, $default)` is not enough here: the grid always sends every
     * filter, empty or not, and `ConvertEmptyStringsToNull` rewrites `?query=` to null -
     * a value that *exists*, so the default never applies and the null reached a string
     * parameter. Arrays (`?query[]=x`) fall back to the default rather than blowing up in
     * a string cast.
     */
    private function textInput(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function eventFromRequest(Request $request): ?Event
    {
        $eventFilter = $request->input('event');

        return $eventFilter ? Event::find($eventFilter) : null;
    }

    private function applyFilters(Builder $query, string $searchTerm, string $speciesFilter): void
    {
        foreach (explode(' ', $searchTerm) as $term) {
            $query->where('fursuits.name', 'LIKE', '%'.trim($term).'%');
        }

        if (! empty($speciesFilter)) {
            $query->whereHas('species', function ($q) use ($speciesFilter) {
                $q->where('name', 'LIKE', '%'.$speciesFilter.'%');
            });
        }
    }

    private function presentFursuits($fursuits, bool $isHistoricalEvent): Collection
    {
        return $fursuits->map(function ($fursuit) use ($isHistoricalEvent) {
            return [
                'id' => $fursuit->id,
                'name' => $fursuit->name,
                'species' => $fursuit->species->name,
                'image' => $fursuit->image_webp_url,
                'thumb' => $fursuit->image_thumb_url,
                'scoring' => $isHistoricalEvent ? 0 : ($fursuit->catched_by_users_count ?? 0),
                'event' => $fursuit->event ? $fursuit->event->name : null,
                'archival_notice' => $fursuit->event ? $fursuit->event->archival_notice : null,
            ];
        });
    }

    /**
     * The leaderboard block for a page, named after the convention it belongs to.
     *
     * Deliberately not `user_catch_rankings`: that table holds one all-time row per user,
     * rebuilt from every catch of every year, so pairing it with an event name is a lie
     * as soon as a second catch-em-all convention exists. It labelled the standings of
     * EF28+EF29 as "EF30 leaders" purely because EF30 was the newest event with the
     * feature switched on, months before its first catch. These are counted per event.
     *
     * An event's own folder shows its own standings, whatever year it is. The pages that
     * are not about a particular year - the folder overview and "all fursuits" - only
     * show the current calendar year's convention, and only once it has catches, so the
     * block appears when the game starts and is gone in January.
     *
     * @return array{ranking: Collection, ranking_event: ?string}
     */
    private function rankingFor(?Event $selectedEvent): array
    {
        $hidden = ['ranking' => collect(), 'ranking_event' => null];

        if ($selectedEvent) {
            if (! $selectedEvent->catch_em_all_enabled) {
                return $hidden;
            }

            $ranking = $this->topRankings($selectedEvent);

            return $ranking->isEmpty()
                ? $hidden
                : ['ranking' => $ranking, 'ranking_event' => $selectedEvent->name];
        }

        // Newest first, so a year holding two conventions prefers the later one - but
        // only if it has actually been played.
        foreach ($this->currentYearCatchEvents() as $event) {
            $ranking = $this->topRankings($event);

            if ($ranking->isNotEmpty()) {
                return ['ranking' => $ranking, 'ranking_event' => $event->name];
            }
        }

        return $hidden;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Event>
     */
    private function currentYearCatchEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return Event::query()
            ->where('catch_em_all_enabled', true)
            ->whereYear('starts_at', now()->year)
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * Top three catchers of one convention.
     *
     * Catches hang off `event_users`, which is what ties a catch to a year. Ties share a
     * rank (two players on 300 are both #1) and are broken for display by who got there
     * first, matching how the in-game leaderboard reads.
     */
    private function topRankings(Event $event): Collection
    {
        $rows = Cache::remember(
            self::RANKING_CACHE_KEY.$event->id,
            now()->addMinutes(self::RANKING_CACHE_MINUTES),
            fn () => UserCatch::query()
                ->join('event_users', 'event_users.id', '=', 'user_catches.event_user_id')
                ->join('users', 'users.id', '=', 'event_users.user_id')
                ->where('event_users.event_id', $event->id)
                ->groupBy('event_users.user_id', 'users.name')
                ->selectRaw('users.name as user, COUNT(*) as catches, MAX(user_catches.created_at) as last_catch')
                ->orderByDesc('catches')
                ->orderBy('last_catch')
                ->limit(3)
                ->get()
                ->map(fn ($row) => ['user' => $row->user, 'catches' => (int) $row->catches])
                ->all()
        );

        $rank = 0;
        $previousScore = null;

        return collect($rows)->values()->map(function (array $row, int $index) use (&$rank, &$previousScore) {
            if ($row['catches'] !== $previousScore) {
                $rank = $index + 1;
                $previousScore = $row['catches'];
            }

            return [...$row, 'rank' => $rank];
        });
    }

    /**
     * Counts and a cover photo per event. Storage paths, not URLs: the caller signs them,
     * so a cached folder cannot serve a link that has already expired.
     *
     * @return array{events: array<int, array<string, mixed>>, total_fursuits: int, total_cover: ?string}
     */
    private function buildFolders(): array
    {
        $counts = $this->publishedFursuits()
            ->selectRaw('event_id, COUNT(*) as fursuits')
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        $events = Event::query()
            ->whereIn('id', $counts->keys()->filter()->all())
            ->orderByDesc('starts_at')
            ->get();

        $folders = $events->map(function (Event $event) use ($counts) {
            $row = $counts->get($event->id);

            return [
                'id' => $event->id,
                'name' => $event->name,
                'year' => $event->starts_at?->format('Y'),
                'fursuits' => (int) ($row->fursuits ?? 0),
                'archival_notice' => $event->archival_notice,
                'catch_em_all_enabled' => (bool) $event->catch_em_all_enabled,
                'cover' => $this->coverPathFor($event),
            ];
        })->all();

        return [
            'events' => $folders,
            'total_fursuits' => (int) $counts->sum('fursuits'),
            'total_cover' => $folders[0]['cover'] ?? null,
        ];
    }

    /**
     * The photo on the folder card: the event's most caught fursuit, or its newest one
     * when the event predates catch-em-all.
     */
    private function coverPathFor(Event $event): ?string
    {
        $query = $this->publishedFursuits()->where('event_id', $event->id);

        if ($event->catch_em_all_enabled) {
            $query->withCount('catchedByUsers')->orderByDesc('catched_by_users_count');
        }

        $fursuit = $query->orderByDesc('id')->first();

        // Folder cards are the same size as grid cards, so they take the thumbnail too.
        // Never the master: `publishedFursuits()` already requires a rendered thumb.
        return $fursuit?->image_thumb;
    }

    private function applyGallerySorting(Builder $query, string $sortBy, bool $isHistoricalEvent)
    {
        // Apply sorting - skip catch-related sorting for historical events (EF15-EF27)
        // Catch related sort at 1st place
        if (! $isHistoricalEvent) {
            $query->withCount('catchedByUsers'); // Always adding Catch Values if event can contain these

            if ($sortBy === 'catches_asc') {
                $query->orderBy('catched_by_users_count');
            } elseif ($sortBy === 'catches_desc') {
                $query->orderByDesc('catched_by_users_count');
            }
        }

        // Name base sorting at 2nd place
        switch ($sortBy) {
            default:
            case 'name_asc':
                $query->orderBy('name');
                break;
            case 'name_desc':
                $query->orderByDesc('name');
                break;
        }

        // Event base sorting at 3rd place (unfortunately event_id is not suitable)
        $query->orderByLeftPowerJoins('event.name', 'desc');
    }
}
