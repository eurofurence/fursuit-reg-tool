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
            $rank = $this->calculateUserRank($totalCatches, $eventUser->event_id);

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

    public function getLeaderboard(?Event $filterEvent, int $limit = 10, int $rankCutoff = 3): array
    {
        $cacheKey = 'leaderboard_'.($filterEvent->id ?? 'global');

        $result = Cache::remember($cacheKey, 600, function () use ($filterEvent, $limit, $rankCutoff) {
            $profileUuid = function ($eventUser) {
                $profile = $eventUser?->user?->userProfile;

                return $profile?->approved_at !== null ? $profile->uuid : null;
            };

            if ($filterEvent) {
                $rows = EventUser::where('event_id', $filterEvent->id)
                    ->with('user.userProfile')
                    ->withCount(['fursuitsCatched'])
                    ->having('fursuits_catched_count', '>', 0)
                    ->orderByDesc('fursuits_catched_count')
                    ->limit($limit)
                    ->get()
                    ->map(fn ($eventUser) => [
                        'event_user_id' => $eventUser->id,
                        'user_id' => $eventUser->user_id,
                        'name' => $eventUser->user->name ?? 'Unknown User',
                        'catches' => $eventUser->fursuits_catched_count ?? 0,
                        'profile_uuid' => $profileUuid($eventUser),
                    ]);
            } else {
                $rows = EventUser::with('user.userProfile')
                    ->withCount('fursuitsCatched')
                    ->having('fursuits_catched_count', '>', 0)
                    ->get()
                    ->groupby('user_id')
                    ->map(fn ($group) => [
                        'event_user_id' => $group->first()->id,
                        'user_id' => $group->first()->user_id,
                        'name' => $group->first()->user->name ?? 'Unknown User',
                        'catches' => $group->sum('fursuits_catched_count'),
                        'profile_uuid' => $profileUuid($group->first()),
                    ]);
            }

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

    public function getUserCollection(User $user, ?Event $filterEvent = null): array
    {
        $cacheKey = "collection_{$user->id}_".($filterEvent?->id ?? 'global');

        $result = Cache::remember($cacheKey, 600, function () use ($user, $filterEvent) {
            $eventUserIds = EventUser::where('user_id', $user->id)
                ->when($filterEvent, fn ($q) => $q->where('event_id', $filterEvent->id))
                ->pluck('id');

            $catches = UserCatch::whereIn('event_user_id', $eventUserIds)
                ->with(['fursuit.species', 'fursuit.user.userProfile'])
                ->get()
                ->unique('fursuit_id');

            $fursuits = [];
            $speciesIndex = [];

            foreach ($catches as $catch) {
                $rarity = $catch->getFursuitRarity();
                $specie = $catch->getFursuitSpecies();
                $catch_count = $catch->getCatches();
                $ownerProfile = $catch->fursuit->user?->userProfile;
                $fursuits[] = [
                    'species' => $specie,
                    'count' => $catch_count,
                    'profileUuid' => $ownerProfile?->approved_at !== null ? $ownerProfile->uuid : null,
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

    public function getDetailedCollection(EventUser $eventUser): array
    {
        $query = UserCatch::where('event_user_id', $eventUser->id)
            ->with(['fursuit.species', 'fursuit.user']);

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
