<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\FursuitRarity;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class GameStatsService
{
    public function getUserStats(EventUser $eventUser): array
    {
        $cacheKey = "game_stats_{$eventUser->id}";

        return Cache::remember($cacheKey, 600, function () use ($eventUser) {
            $catches = UserCatch::where('event_user_id', $eventUser->id)->with('fursuit.species')->get();

            $totalCatches = $catches->count();
            $uniqueSpecies = $catches->pluck('fursuit.species.id')->unique()->count();

            // Calculate rank
            $rank = $this->calculateUserRank($totalCatches);

            // Calculate rarity distribution
            $rarityStats = $this->calculateRarityDistribution($catches);

            // Get available fursuiters count
            $totalAvailable = $this->getTotalAvailableFursuiters($eventUser->event);

            return [
                'rank' => $rank,
                'totalCatches' => $totalCatches,
                'uniqueSpecies' => $uniqueSpecies,
                'totalAvailable' => $totalAvailable,
                'completionPercentage' => $totalAvailable > 0 ? round(($totalCatches / $totalAvailable) * 100, 1) : 0,
                'rarityStats' => $rarityStats,
            ];
        });
    }

    public function getLeaderboard(Event $filterEvent, int $limit = 10, int $rankCutoff = 3): array
    {
        $cacheKey = 'leaderboard_'.$filterEvent->id;

        $result = Cache::remember($cacheKey, 600, function () use ($filterEvent, $limit, $rankCutoff) {
            $rows = EventUser::where('event_id', $filterEvent->id)
                ->with('user')
                ->withCount(['fursuitsCatched'])
                ->having('fursuits_catched_count', '>', 0)
                ->orderByDesc('fursuits_catched_count')
                ->limit($limit)
                ->get()
                ->map(fn ($eventUser) => [
                    'id' => $eventUser->user_id,
                    'name' => $eventUser->user->name ?? 'Unknown User',
                    'catches' => $eventUser->fursuits_catched_count ?? 0,
                ]);

            $rows = $rows
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
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'rank' => $rank,
                    'catches' => $row['catches'],
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
                ->with(['fursuit.species'])
                ->get()
                ->unique('fursuit_id');

            $fursuits = [];
            $speciesIndex = [];

            foreach ($catches as $catch) {
                $rarity = $catch->getFursuitRarity();
                $specie = $catch->getFursuitSpecies();
                $catch_count = $catch->getCatches();
                $fursuits[] = [
                    'species' => $specie,
                    'count' => $catch_count,
                    'rarity' => [
                        'level' => $rarity->value,
                        'label' => $rarity->getLabel(),
                        'color' => $rarity->getColor(),
                        'icon' => $rarity->getIcon(),
                    ],
                    'gallery' => [
                        'id' => $catch->fursuit->id,
                        'name' => $catch->fursuit->name,
                        'species' => $catch->fursuit->species->name,
                        'image' => $catch->fursuit->image_webp_url,
                        'scoring' => $catch_count,
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
            ? sprintf('collection_user_%d', $user->id)
            : sprintf('collection_user_%d_events_%s', $user->id, $eventIds->implode('_'));

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
                ->with(['fursuit.species'])
                ->get()
                ->unique('fursuit_id');

            $fursuits = [];
            $speciesIndex = [];

            foreach ($catches as $catch) {
                $rarity = $catch->getFursuitRarity();
                $specie = $catch->getFursuitSpecies();
                $catch_count = $catch->getCatches();
                $fursuits[] = [
                    'species' => $specie,
                    'count' => $catch_count,
                    'rarity' => [
                        'level' => $rarity->value,
                        'label' => $rarity->getLabel(),
                        'color' => $rarity->getColor(),
                        'icon' => $rarity->getIcon(),
                    ],
                    'gallery' => [
                        'id' => $catch->fursuit->id,
                        'name' => $catch->fursuit->name,
                        'species' => $catch->fursuit->species->name,
                        'image' => $catch->fursuit->image_webp_url,
                        'scoring' => $catch_count,
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

    private function calculateUserRank(int $userCatches): int
    {
        $query = EventUser::withCount([
            'fursuitsCatched',
        ])
            ->having('fursuits_catched_count', '>', $userCatches)
            ->get()
            ->groupBy('fursuits_catched_count');

        return $query->count() + 1;
    }

    /**
     * Summary of calculateRarityDistribution
     *
     * @param  UserCatch[]  $catches
     * @return array<array{color: string, count: int, icon: string, label: string|int[]>}
     */
    private function calculateRarityDistribution(Collection $catches): array
    {
        $distribution = [];

        foreach (FursuitRarity::cases() as $rarity) {
            $distribution[$rarity->value] = [
                'count' => 0,
                'label' => $rarity->getLabel(),
                'color' => $rarity->getColor(),
                'icon' => $rarity->getIcon(),
            ];
        }

        foreach ($catches as $catch) {
            $rarity = $catch->getFursuitRarity();
            $distribution[$rarity->value]['count']++;
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
