<?php

namespace App\Http\Controllers\GALLERY;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FCEA\UserCatchRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GalleryController extends Controller
{
    const ITEMS_PER_LOAD = 20; // 20 items per infinite scroll load

    public function index(Request $request): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        $searchTerm = $request->input('query', '');
        $speciesFilter = $request->input('species', '');
        $eventFilter = $request->input('event', '');
        // Set default sort based on whether catch-em-all is enabled
        $defaultSort = 'catches_desc';
        if (! empty($eventFilter)) {
            $tempEvent = Event::find($eventFilter);
            if ($tempEvent && ! $tempEvent->catch_em_all_enabled) {
                $defaultSort = 'name_asc';
            }
        }
        $sortBy = $request->input('sort', $defaultSort); // catches_desc, catches_asc, name_asc, name_desc
        $offset = intval($request->input('offset', 0));

        $search = collect(explode(' ', $searchTerm))->map(function ($term) {
            return '%'.trim($term).'%';
        })->toArray();

        if ($offset < 0) {
            $offset = 0;
        }

        // Build base query
        $query = Fursuit::query()
            ->with(['species', 'event'])
            ->where('status', 'approved')
            ->whereNotNull('image')
            ->where('published', true);

        // Apply search filter
        foreach ($search as $term) {
            $query->where('fursuits.name', 'LIKE', $term);
        }

        // Apply species filter
        if (! empty($speciesFilter)) {
            $query->whereHas('species', function ($q) use ($speciesFilter) {
                $q->where('name', 'LIKE', '%'.$speciesFilter.'%');
            });
        }

        // Apply event filter and get event data
        $selectedEvent = null;
        $isHistoricalEvent = false;
        if (! empty($eventFilter)) {
            $query->where('event_id', $eventFilter);
            $selectedEvent = Event::find($eventFilter);
            if ($selectedEvent) {
                $isHistoricalEvent = ! $selectedEvent->catch_em_all_enabled;
            }
        }

        // Get total count
        $totalCount = $query->count();

        // Move duplicated sort to func
        $this->applyGallerySorting($query, $sortBy, $isHistoricalEvent);

        $fursuits = $query->offset($offset)
            ->limit(self::ITEMS_PER_LOAD)
            ->get();

        $hasMore = ($offset + self::ITEMS_PER_LOAD) < $totalCount;

        $topRankings = UserCatchRanking::query()
            ->whereNotNull('user_id')
            ->orderBy('rank', 'asc')
            ->limit(3)
            ->with('user')
            ->get();

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

        $fursuitData = $fursuits->map(function ($fursuit) use ($isHistoricalEvent) {
            return [
                'id' => $fursuit->id,
                'name' => $fursuit->name,
                'species' => $fursuit->species->name,
                'image' => $fursuit->image_webp_url,
                'scoring' => $isHistoricalEvent ? 0 : ($fursuit->catched_by_users_count ?? 0),
                'event' => $fursuit->event ? $fursuit->event->name : null,
                'archival_notice' => $fursuit->event ? $fursuit->event->archival_notice : null,
            ];
        });

        return Inertia::render('Gallery/GalleryIndex', [
            'fursuits' => $fursuitData,
            'has_more' => $hasMore,
            'total' => $totalCount,
            'is_historical_event' => $isHistoricalEvent,
            'selected_event' => $selectedEvent ? [
                'id' => $selectedEvent->id,
                'name' => $selectedEvent->name,
                'archival_notice' => $selectedEvent->archival_notice,
                'catch_em_all_enabled' => $selectedEvent->catch_em_all_enabled,
            ] : null,
            'ranking' => $topRankings
                ->map(function ($ranking) {
                    return [
                        'user' => $ranking->user->name,
                        'rank' => $ranking->rank,
                        'catches' => $ranking->score,
                    ];
                }),
            'filters' => [
                'search' => $searchTerm,
                'species' => $speciesFilter,
                'event' => $eventFilter,
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

    public function loadMore(Request $request): \Illuminate\Http\JsonResponse
    {
        $searchTerm = $request->input('query', '');
        $speciesFilter = $request->input('species', '');
        $eventFilter = $request->input('event', '');
        // Set default sort based on whether catch-em-all is enabled
        $defaultSort = 'catches_desc';
        if (! empty($eventFilter)) {
            $tempEvent = Event::find($eventFilter);
            if ($tempEvent && ! $tempEvent->catch_em_all_enabled) {
                $defaultSort = 'name_asc';
            }
        }
        $sortBy = $request->input('sort', $defaultSort);
        $offset = intval($request->input('offset', 0));

        if ($offset < 0) {
            $offset = 0;
        }

        $search = collect(explode(' ', $searchTerm))->map(function ($term) {
            return '%'.trim($term).'%';
        })->toArray();

        // Build base query
        $query = Fursuit::query()
            ->with(['species', 'event'])
            ->where('status', 'approved')
            ->whereNotNull('image')
            ->where('published', true);

        // Apply search filter
        foreach ($search as $term) {
            $query->where('name', 'LIKE', $term);
        }

        // Apply species filter
        if (! empty($speciesFilter)) {
            $query->whereHas('species', function ($q) use ($speciesFilter) {
                $q->where('name', 'LIKE', '%'.$speciesFilter.'%');
            });
        }

        // Apply event filter and get event data
        $selectedEvent = null;
        $isHistoricalEvent = false;
        if (! empty($eventFilter)) {
            $query->where('event_id', $eventFilter);
            $selectedEvent = Event::find($eventFilter);
            if ($selectedEvent) {
                $isHistoricalEvent = ! $selectedEvent->catch_em_all_enabled;
            }
        }

        // Get total count
        $totalCount = $query->count();

        // Move duplicated sort to func
        $this->applyGallerySorting($query, $sortBy, $isHistoricalEvent);

        $fursuits = $query->offset($offset)
            ->limit(self::ITEMS_PER_LOAD)
            ->get();

        $hasMore = ($offset + self::ITEMS_PER_LOAD) < $totalCount;

        $fursuitData = $fursuits->map(function ($fursuit) use ($isHistoricalEvent) {
            return [
                'id' => $fursuit->id,
                'name' => $fursuit->name,
                'species' => $fursuit->species->name,
                'image' => $fursuit->image_webp_url,
                'scoring' => $isHistoricalEvent ? 0 : ($fursuit->catched_by_users_count ?? 0),
                'event' => $fursuit->event ? $fursuit->event->name : null,
                'archival_notice' => $fursuit->event ? $fursuit->event->archival_notice : null,
            ];
        });

        return response()->json([
            'fursuits' => $fursuitData,
            'has_more' => $hasMore,
            'total' => $totalCount,
        ]);
    }

    public function getTotalFursuitCount(Request $request): \Illuminate\Http\JsonResponse
    {
        $count = Fursuit::query()
            ->where('status', 'approved')
            ->where('published', true)
            ->count();

        return response()->json(['count' => $count]);
    }

    private function applyGallerySorting(Builder $query, string $sortBy, bool $isHistoricalEvent)
    {
        // Apply sorting - skip catch-related sorting for historical events (EF15-EF27)
        // Catch related sort at 1st place
        if (! $isHistoricalEvent && ($sortBy === 'catches_asc' || $sortBy === 'catches_desc')) {
            $query->withCount('catchedByUsers');

            if ($sortBy === 'catches_asc')
                $query->orderBy('catched_by_users_count');
            else if($sortBy === 'catches_desc')
                $query->orderByDesc('catched_by_users_count');
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
        $query->orderByLeftPowerJoins('event.name','desc');
    }
}
