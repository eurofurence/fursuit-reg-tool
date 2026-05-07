<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\FursuitRanking;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
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
        $cacheKey = "leaderboard_{$filterEvent->id}";

        $result = Cache::remember($cacheKey, 600, function () use ($filterEvent, $limit, $rankCutoff) {
            $query = EventUser::where('event_id', $filterEvent->id)
                ->withCount(['fursuitsCatched'])
                ->having('fursuits_catched_count', '>', 0)
                ->orderByDesc('fursuits_catched_count')
                ->limit($limit);

            $eventUsers = $query->get();
            $leaderboard = [];

            $rank = 1;
            $lastCatch = 0;

            foreach ($eventUsers as $index => $eventUser) {
                if ($lastCatch > $eventUser->fursuits_catched_count) {
                    $rank++;
                    if ($rank > $rankCutoff) {
                        break;
                    }
                }
                $leaderboard[] = [
                    'event_user_id' => $eventUser->id,
                    'name' => $eventUser->user->name ?? 'Unknown User',
                    'rank' => $rank,
                    'catches' => $eventUser->fursuits_catched_count ?? 0,
                ];
                $lastCatch = $eventUser->fursuits_catched_count;
            }

            return $leaderboard;
        });

        // Ensure we always return an array, even if cache returns something else
        return is_array($result) ? $result : [];
    }

    public function getUserLeaderboard(EventUser $eventUser, int $userRank, int $userCatched, ?string $userName = null, int $rankCutoff = 3): array
    {
        $cacheKey = "user_leaderboard_{$eventUser}";

        $result = Cache::remember($cacheKey, 600, function () use ($eventUser, $userName, $userCatched, $userRank, $rankCutoff) {
            $lower = EventUser::where('event_id', $eventUser->event_id)
                ->withCount(['fursuitsCatched'])
                ->having('fursuits_catched_count', '>', 0)
                ->having('fursuits_catched_count', '<=', $userCatched)
                ->orderByDesc('fursuits_catched_count')
                ->limit(3);

            $upper = EventUser::where('event_id', $eventUser->event_id)
                ->withCount(['fursuitsCatched'])
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
                'id' => $eventUser->id,
                'name' => $userName ?? 'Unknown User',
                'rank' => $userRank,
                'catches' => $userCatched,
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

    public function getUserCollection(EventUser $eventUser): array
    {
        $cacheKey = "collection_{$eventUser->id}";

        return Cache::remember($cacheKey, 600, function () use ($eventUser) {
            $query = UserCatch::where('event_user_id', $eventUser->id)
                ->with(['fursuit']);

            /**
             * @var Collection<UserCatch>|UserCatch[] $catches
             */
            $catches = $query->get();
            $fursuits = [];
            $speciesIndex = [];

            foreach ($catches as $catch) {
                $ranking = $catch->getFursuitRanking();
                $specie = $catch->getFursuitSpecies();
                $catch_count = $catch->getCatches();
                $fursuits[] = [
                    'species' => $specie,
                    'count' => $catch_count,
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
    }

    public function getDetailedCollection(EventUser $eventUser): array
    {
        $query = UserCatch::where('event_user_id', $eventUser->id)
            ->with(['fursuit.species', 'fursuit.user']);

        /**
         * @var Collection<UserCatch>|UserCatch[] $catches
         */
        $catches = $query->orderByDesc('created_at')->get();

        $result = [];
        foreach ($catches as $catch) {
            $ranking = $catch->getFursuitRanking();

            $result[] = [
                'id' => $catch->id,
                'fursuitName' => $catch->fursuit?->name ?? 'Unknown Fursuit',
                'species' => $catch->fursuit?->species?->name ?? 'Unknown',
                'owner' => $catch->fursuit?->user?->name ?? 'Anonymous',
                'image' => $catch->fursuit?->image,
                'caughtAt' => $catch->created_at,
                'ranking' => [
                    'level' => $ranking->value,
                    'label' => $ranking->getLabel(),
                    'color' => $ranking->getColor(),
                    'gradient' => $ranking->getGradient(),
                    'icon' => $ranking->getIcon(),
                ],
            ];
        }

        return $result;
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
            $ranking = $catch->getFursuitRanking();
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
