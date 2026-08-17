<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\FursuitRanking;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class GameStatsService
{
    public function __construct(
        private SpeciesPopulationService $species,
        private FursuitRankingService $ranking,
    ) {}

    public function getUserStats(EventUser $eventUser): array
    {
        $cacheKey = "game_stats_{$eventUser->id}";

        return Cache::remember($cacheKey, 600, function () use ($eventUser) {
            $catches = UserCatch::where('event_user_id', $eventUser->id)->with(['fursuit.species', 'fursuit.event'])->get();

            $totalCatches = $catches->count();
            $uniqueSpecies = $catches->pluck('fursuit.species.id')->unique()->count();

            // Calculate rank
            $rank = $this->calculateUserRank($totalCatches, $eventUser->event_id);

            // Calculate ranking distribution
            $rankingStats = $this->calculateRankingDistribution($catches);

            // Get available fursuiters count
            $totalAvailable = $this->getTotalAvailableFursuiters($eventUser->event);

            return [
                'rank' => $rank,
                'totalCatches' => $totalCatches,
                'uniqueSpecies' => $uniqueSpecies,
                'totalAvailable' => $totalAvailable,
                'completionPercentage' => $totalAvailable > 0 ? round(($totalCatches / $totalAvailable) * 100, 1) : 0,
                'rankingStats' => $rankingStats,
            ];
        });
    }

    public function getLeaderboard(Event $filterEvent, int $limit = 10, int $rankCutoff = 3): array
    {
        $cacheKey = 'leaderboard_'.$filterEvent->id;

        $result = Cache::remember($cacheKey, 600, function () use ($filterEvent, $limit, $rankCutoff) {
            $profileUuid = function ($eventUser) {
                $profile = $eventUser?->user?->userProfile;

                return $profile?->approved_at !== null ? $profile->uuid : null;
            };
            $rows = EventUser::where('event_id', $filterEvent->id)
                ->with('user.userProfile')
                ->withCount('fursuitsCatched')
                ->having('fursuits_catched_count', '>', 0)
                ->get()
                ->groupBy('user_id')
                ->map(fn ($group) => [
                    'event_user_id' => $group->first()->id,
                    'user_id' => $group->first()->user_id,
                    'name' => $group->first()->user->name ?? 'Unknown User',
                    'catches' => $group->sum('fursuits_catched_count'),
                    'profile_uuid' => $profileUuid($group->first()),
                ])
                ->sortBy([
                    ['catches', 'desc'],
                    ['name', 'asc'],
                ])
                ->take($limit)
                ->values();

            $leaderboard = [];
            $rank = 1;
            $lastCatch = null;

            foreach ($rows as $row) {
                if ($lastCatch !== null && $lastCatch > $row['catches']) {
                    $rank++;
                    if ($rank > $rankCutoff) {
                        break;
                    }
                }

                $leaderboard[] = [
                    'event_user_id' => $row['event_user_id'],
                    'user_id' => $row['user_id'],
                    'name' => $row['name'],
                    'rank' => $rank,
                    'catches' => $row['catches'],
                    'profile_uuid' => $row['profile_uuid'],
                ];

                $lastCatch = $row['catches'];
            }

            return $leaderboard;
        });

        return is_array($result) ? $result : [];
    }

    public function getUserCollection(EventUser $eventUser): array
    {
        $cacheKey = "collection_{$eventUser->id}";

        $result = Cache::remember($cacheKey, 600, function () use ($eventUser) {
            $catches = UserCatch::where('event_user_id', $eventUser->id)
                ->with(['fursuit.species', 'fursuit.event', 'fursuit.user.userProfile'])
                ->get()
                ->unique('fursuit_id');

            $fursuits = [];
            $speciesIndex = [];

            foreach ($catches as $catch) {
                $event = $catch->fursuit->event;
                $ranking = $this->ranking->forFursuit($event, $catch->fursuit);
                $specie = $catch->getFursuitSpecies();
                $population = $this->species->population($event, $catch->fursuit->species_id);
                $ownerProfile = $catch->fursuit->user?->userProfile;
                $fursuits[] = [
                    'species' => $specie,
                    // how many of this species are registered for the event, which is
                    // a fact about the species rather than a tier
                    'count' => $population,
                    'caught' => $this->ranking->catches($event, $catch->fursuit->id),
                    'profileUuid' => $ownerProfile?->approved_at !== null ? $ownerProfile->uuid : null,
                    'ranking' => [
                        'level' => $ranking->value,
                        'label' => $ranking->getLabel(),
                        'color' => $ranking->getColor(),
                        'icon' => $ranking->getIcon(),
                    ],
                    'gallery' => [
                        'id' => $catch->fursuit->id,
                        'name' => $catch->fursuit->name,
                        'species' => $catch->fursuit->species->name,
                        'image' => $catch->fursuit->image_thumb_url,
                        'scoring' => $population,
                        'owner' => $catch->fursuit->user?->name,
                        'profileUuid' => $ownerProfile?->approved_at !== null ? $ownerProfile->uuid : null,
                    ],
                ];
                $speciesIndex[$specie] = ($speciesIndex[$specie] ?? 0) + 1;
            }

            // Sort by total catches descending
            usort($fursuits, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });

            return [
                'suits' => $fursuits,
                'species' => $speciesIndex,
                'totalCatches' => $catches->count(),
            ];
        });

        return is_array($result) ? $result : [];
    }

    /**
     * @param  Event[]|int[]  $events
     */
    public function getUserCollectionForEvents(User $user, array $events, bool $useGlobalCacheKey = false): array
    {
        $eventIds = collect($events)
            ->map(fn ($event) => $event instanceof Event ? $event->id : (int) $event)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values();

        if ($eventIds->isEmpty()) {
            return [
                'suits' => [],
                'species' => [],
                'totalCatches' => 0,
            ];
        }

        $cacheKey = $useGlobalCacheKey
            ? sprintf('collection_v2_user_%d', $user->id)
            : sprintf('collection_v2_user_%d_events_%s', $user->id, $eventIds->implode('_'));

        $result = Cache::remember($cacheKey, 600, function () use ($user, $eventIds) {
            $eventUserIds = EventUser::where('user_id', $user->id)
                ->whereIn('event_id', $eventIds)
                ->pluck('id');

            if ($eventUserIds->isEmpty()) {
                return [
                    'suits' => [],
                    'species' => [],
                    'totalCatches' => 0,
                ];
            }

            $catches = UserCatch::whereIn('event_user_id', $eventUserIds)
                ->with(['fursuit.species', 'fursuit.event', 'fursuit.user.userProfile'])
                ->get()
                ->unique('fursuit_id');

            $fursuits = [];
            $speciesIndex = [];

            foreach ($catches as $catch) {
                $event = $catch->fursuit->event;
                $ranking = $this->ranking->forFursuit($event, $catch->fursuit);
                $specie = $catch->getFursuitSpecies();
                $population = $this->species->population($event, $catch->fursuit->species_id);
                $ownerProfile = $catch->fursuit->user?->userProfile;
                $fursuits[] = [
                    'species' => $specie,
                    // how many of this species are registered for the event, which is
                    // a fact about the species rather than a tier
                    'count' => $population,
                    'caught' => $this->ranking->catches($event, $catch->fursuit->id),
                    'profileUuid' => $ownerProfile?->approved_at !== null ? $ownerProfile->uuid : null,
                    'ranking' => [
                        'level' => $ranking->value,
                        'label' => $ranking->getLabel(),
                        'color' => $ranking->getColor(),
                        'icon' => $ranking->getIcon(),
                    ],
                    'gallery' => [
                        'id' => $catch->fursuit->id,
                        'name' => $catch->fursuit->name,
                        'species' => $catch->fursuit->species->name,
                        'image' => $catch->fursuit->image_thumb_url,
                        'scoring' => $population,
                        'owner' => $catch->fursuit->user?->name,
                        'profileUuid' => $ownerProfile?->approved_at !== null ? $ownerProfile->uuid : null,
                    ],
                ];
                $speciesIndex[$specie] = ($speciesIndex[$specie] ?? 0) + 1;
            }

            // Sort by total catches descending
            usort($fursuits, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });

            return [
                'suits' => $fursuits,
                'species' => $speciesIndex,
                'totalCatches' => $catches->count(),
            ];
        });

        return is_array($result) ? $result : [];
    }

    private function calculateUserRank(int $userCatches, int $eventId): int
    {
        $higherScores = UserCatch::query()
            ->whereHas('event_user', fn ($query) => $query->where('event_id', $eventId))
            ->selectRaw('count(*) as total')
            ->groupBy('event_user_id')
            ->havingRaw('count(*) > ?', [$userCatches])
            ->pluck('total')
            ->unique()
            ->count();

        return $higherScores + 1;
    }

    /**
     * Summary of calculateRankingDistribution
     *
     * @param  UserCatch[]  $catches
     * @return array<array{color: string, count: int, icon: string, label: string|int[]>}
     */
    private function calculateRankingDistribution(Collection $catches): array
    {
        $distribution = [];

        foreach (FursuitRanking::cases() as $ranking) {
            $distribution[$ranking->value] = [
                'count' => 0,
                'label' => $ranking->getLabel(),
                'color' => $ranking->getColor(),
                'icon' => $ranking->getIcon(),
            ];
        }

        foreach ($catches as $catch) {
            $ranking = $this->ranking->forFursuit($catch->fursuit->event, $catch->fursuit);
            $distribution[$ranking->value]['count']++;
        }

        return $distribution;
    }

    private function getTotalAvailableFursuiters(Event $filterEvent): int
    {
        return Cache::remember(
            "total_fursuiters_{$filterEvent->id}",
            3600,
            fn () => Fursuit::where('event_id', $filterEvent->id)
                ->where('catch_em_all', true)
                ->whereNull('deleted_at')
                ->count()
        );
    }
}
