<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\FursuitRarity;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

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

    public function getLeaderboard($filterEvent = null, bool $isGlobal = false, int $limit = 10, int $rankCutoff = 3): array
    {
        $cacheKey = $isGlobal ? "leaderboard_global_{$limit}" : "leaderboard_{$filterEvent?->id}_{$limit}";

        $result = Cache::remember($cacheKey, 600, function () use ($filterEvent, $isGlobal, $limit, $rankCutoff) {
            $query = User::withCount([
                'fursuitsCatched' => function ($q) use ($filterEvent, $isGlobal) {
                    if (!$isGlobal && $filterEvent) {
                        $q->where('event_id', $filterEvent->id);
                    }
                }
            ])
                ->having('fursuits_catched_count', '>', 0)
                ->orderByDesc('fursuits_catched_count')
                ->limit($limit);

            $users = $query->get();
            $leaderboard = [];

            $rank = 1;
            $lastCatch = 0;

            foreach ($users as $index => $user) {
                if ($lastCatch > $user->fursuits_catched_count) {
                    $rank++;
                    if ($rank > $rankCutoff)
                        break;
                }
                $leaderboard[] = [
                    'id' => $user->id,
                    'name' => $user->name ?? 'Unknown User',
                    'rank' => $rank,
                    'catches' => $user->fursuits_catched_count ?? 0,
                ];
                $lastCatch = $user->fursuits_catched_count;
            }

            return $leaderboard;
        });

        // Ensure we always return an array, even if cache returns something else
        return is_array($result) ? $result : [];
    }

    public function getUserLeaderboard(int $userId, int $userRank, int $userCatched, string $userName = null, int $rankCutoff = 3, $filterEvent = null, bool $isGlobal = false): array
    {
        $cacheKey = $isGlobal ? "user_leaderboard_global" : "user_leaderboard_{$filterEvent?->id}";

        $result = Cache::remember($cacheKey, 600, function () use ($filterEvent, $isGlobal, $userId, $userName, $userCatched, $userRank, $rankCutoff) {
            $lower = User::withCount([
                'fursuitsCatched' => function ($q) use ($filterEvent, $isGlobal, $userId) {
                    if (!$isGlobal && $filterEvent) {
                        $q->where('event_id', $filterEvent->id);
                    }
                    $q->where('user_id', '<>', $userId);
                }
            ])
                ->having('fursuits_catched_count', '>', 0)
                ->having('fursuits_catched_count', '<=', $userCatched)
                ->orderByDesc('fursuits_catched_count')
                ->limit(3);


            $upper = User::withCount([
                'fursuitsCatched' => function ($q) use ($filterEvent, $isGlobal) {
                    if (!$isGlobal && $filterEvent) {
                        $q->where('event_id', $filterEvent->id);
                    }
                }
            ])
                ->having('fursuits_catched_count', '>', 0)
                ->having('fursuits_catched_count', '>', $userCatched)
                ->orderBy('fursuits_catched_count')
                ->limit(3);


            $aboveUser = $upper->get();
            $rank = $userRank;
            $lastCatch = $userCatched;

            $leaderboard = [];

            foreach ($aboveUser as $index => $user) {
                if ($lastCatch < $user->fursuits_catched_count) {
                    $rank--;
                    if ($rank <= $rankCutoff) {
                        break;
                    }
                }
                $leaderboard[] = [
                    'id' => $user->id,
                    'name' => $user->name ?? 'Unknown User',
                    'rank' => $rank,
                    'catches' => $user->fursuits_catched_count ?? 0,
                ];
                $lastCatch = $user->fursuits_catched_count;
            }

            $leaderboard = array_reverse($leaderboard);

            $leaderboard[] = [
                'id' => $userId,
                'name' => $userName ?? 'Unknown User',
                'rank' => $userRank,
                'catches' => $userCatched
            ];

            $belowUser = $lower->get();

            $rank = $userRank;
            $lastCatch = $userCatched;

            foreach ($belowUser as $index => $user) {
                if ($lastCatch > $user->fursuits_catched_count) {
                    $rank++;
                }
                $leaderboard[] = [
                    'id' => $user->id,
                    'name' => $user->name ?? 'Unknown User',
                    'rank' => $rank,
                    'catches' => $user->fursuits_catched_count ?? 0,
                ];
                $lastCatch = $user->fursuits_catched_count;
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
                ->with(['fursuit']);

            if (!$isGlobal && $filterEvent) {
                $query->where('event_id', $filterEvent->id);
            }

            $catches = $query->get();
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
                    ]
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
            $rarity = $catch->getFursuitRarity();

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
            ->having('fursuits_catched_count', '>', $userCatches)
            ->get()
            ->groupBy('fursuits_catched_count');

        return $query->count() + 1;
    }

    private function calculateRarityDistribution($catches): array
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

    private function getTotalAvailableFursuiters($filterEvent): int
    {
        if (!$filterEvent) {
            return Fursuit::where('catch_em_all', true)->count();
        }

        return Cache::remember(
            "total_fursuiters_{$filterEvent->id}",
            3600,
            fn() => Fursuit::where('event_id', $filterEvent->id)
                ->where('catch_em_all', true)
                ->count()
        );
    }
}
