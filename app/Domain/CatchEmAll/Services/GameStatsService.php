<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\SpeciesRarity;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GameStatsService
{
    public function getUserStats(User $user, $filterEvent = null, bool $isGlobal = false): array
    {
        $cacheKey = $isGlobal ? "game_stats_global_{$user->id}" : "game_stats_{$filterEvent?->id}_{$user->id}";

        return Cache::remember($cacheKey, 600, function () use ($user, $filterEvent, $isGlobal) {
            $query = UserCatch::where('user_id', $user->id);

            if (!$isGlobal && $filterEvent) {
                $query->where('event_id', $filterEvent->id);
            }

            $catches = $query->with(['fursuit.species'])->get();

            $totalCatches = $catches->count();
            $uniqueSpecies = $catches->pluck('fursuit.species.id')->unique()->count();

            // Calculate rank
            $rank = $this->calculateUserRank($user, $filterEvent, $isGlobal, $totalCatches);

            // Calculate rarity distribution
            $rarityStats = $this->calculateRarityDistribution($catches);

            // Get available fursuiters count
            $totalAvailable = $this->getTotalAvailableFursuiters($filterEvent);

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

    public function getLeaderboard($filterEvent = null, bool $isGlobal = false, int $limit = 10): array
    {
        $cacheKey = $isGlobal ? "leaderboard_global_{$limit}" : "leaderboard_{$filterEvent?->id}_{$limit}";

        $result = Cache::remember($cacheKey, 600, function () use ($filterEvent, $isGlobal, $limit) {
            $query = User::withCount(['fursuitsCatched' => function ($q) use ($filterEvent, $isGlobal) {
                if (!$isGlobal && $filterEvent) {
                    $q->where('event_id', $filterEvent->id);
                }
            }])
            ->having('fursuits_catched_count', '>', 0)
            ->orderByDesc('fursuits_catched_count')
            ->limit($limit);

            $users = $query->get();
            $leaderboard = [];

            foreach ($users as $index => $user) {
                $leaderboard[] = [
                    'id' => $user->id,
                    'name' => $user->name ?? 'Unknown User',
                    'rank' => $index + 1,
                    'catches' => $user->fursuits_catched_count ?? 0,
                ];
            }

            return $leaderboard;
        });

        // Ensure we always return an array, even if cache returns something else
        return is_array($result) ? $result : [];
    }

    public function getUserCollection(User $user, $filterEvent = null, bool $isGlobal = false): array
    {
        $cacheKey = $isGlobal ? "collection_global_{$user->id}" : "collection_{$filterEvent?->id}_{$user->id}";

        return Cache::remember($cacheKey, 600, function () use ($user, $filterEvent, $isGlobal) {
            $query = UserCatch::where('user_id', $user->id)
                ->with(['fursuit.species']);

            if (!$isGlobal && $filterEvent) {
                $query->where('event_id', $filterEvent->id);
            }

            $catches = $query->get();

            // Group by species
            $speciesGrouped = $catches->groupBy('fursuit.species.name');
            $speciesArray = [];

            foreach ($speciesGrouped as $speciesName => $speciesCatches) {
                $firstCatch = $speciesCatches->first();
                $rarity = $firstCatch->getSpeciesRarity();

                $speciesArray[] = [
                    'species' => $speciesName,
                    'count' => $speciesCatches->count(),
                    'rarity' => [
                        'level' => $rarity->value,
                        'label' => $rarity->getLabel(),
                        'color' => $rarity->getColor(),
                        'icon' => $rarity->getIcon(),
                    ],
                    'firstCaught' => $speciesCatches->sortBy('created_at')->first()->created_at,
                ];
            }

            // Sort by total catches descending
            usort($speciesArray, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });

            return [
                'species' => $speciesArray,
                'totalSpecies' => count($speciesArray),
                'totalCatches' => $catches->count(),
            ];
        });
    }

    public function getDetailedCollection(User $user, $filterEvent = null, bool $isGlobal = false): array
    {
        $query = UserCatch::where('user_id', $user->id)
            ->with(['fursuit.species', 'fursuit.user']);

        if (!$isGlobal && $filterEvent) {
            $query->where('event_id', $filterEvent->id);
        }

        $catches = $query->orderByDesc('created_at')->get();

        $result = [];
        foreach ($catches as $catch) {
            $rarity = $catch->getSpeciesRarity();

            $result[] = [
                'id' => $catch->id,
                'fursuitName' => $catch->fursuit?->name ?? 'Unknown Fursuit',
                'species' => $catch->fursuit?->species?->name ?? 'Unknown',
                'owner' => $catch->fursuit?->user?->name ?? 'Anonymous',
                'image' => $catch->fursuit?->image,
                'caughtAt' => $catch->created_at,
                'rarity' => [
                    'level' => $rarity->value,
                    'label' => $rarity->getLabel(),
                    'color' => $rarity->getColor(),
                    'gradient' => $rarity->getGradient(),
                    'icon' => $rarity->getIcon(),
                ],
            ];
        }

        return $result;
    }

    private function calculateUserRank(User $user, $filterEvent, bool $isGlobal, int $userCatches): int
    {
        $query = User::withCount([
            'fursuitsCatched' => function ($q) use ($filterEvent, $isGlobal) {
                if (!$isGlobal && $filterEvent) {
                    $q->where('event_id', $filterEvent->id);
                }
            }
        ])
        ->having('fursuits_catched_count', '>', $userCatches);

        return $query->count() + 1;
    }

    private function calculateRarityDistribution($catches): array
    {
        $distribution = [];

        foreach (SpeciesRarity::cases() as $rarity) {
            $distribution[$rarity->value] = [
                'count' => 0,
                'label' => $rarity->getLabel(),
                'color' => $rarity->getColor(),
                'icon' => $rarity->getIcon(),
            ];
        }

        foreach ($catches as $catch) {
            $rarity = $catch->getSpeciesRarity();
            $distribution[$rarity->value]['count']++;
        }

        return $distribution;
    }

    private function getTotalAvailableFursuiters($filterEvent): int
    {
        if (!$filterEvent) {
            return Fursuit::where('catch_em_all', true)->count();
        }

        return Cache::remember(
            "total_fursuiters_{$filterEvent->id}",
            3600,
            fn () => Fursuit::where('event_id', $filterEvent->id)
                ->where('catch_em_all', true)
                ->count()
        );
    }
}
