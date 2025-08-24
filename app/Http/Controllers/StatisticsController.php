<?php

namespace App\Http\Controllers;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\FCEA\UserCatch;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class StatisticsController extends Controller
{
    public function index()
    {
        // Cache statistics for 10 minutes to improve performance
        $statistics = Cache::remember('system_statistics', 600, function () {
            return $this->generateStatistics();
        });

        if (! Auth::user()?->is_admin ?? true) {
            $statistics = $this->removePrivateData($statistics);
        }

        return Inertia::render('Statistics/Index', $statistics);
    }

    private function removePrivateData(array $statistics): array
    {
        $statistics['fursuits']['top_owners'] = [];
        $statistics['fcea']['top_catchers'] = [];
        $statistics['users']['most_active_users'] = [];

        return $statistics;
    }

    private function generateStatistics(): array
    {
        $currentEvent = Event::getActiveEvent();

        return [
            'overview' => $this->getOverviewStats($currentEvent),
            // Only show stats for the current event
            'badges' => $this->getBadgeStats($currentEvent),
            'fursuits' => $this->getFursuitStats($currentEvent),
            'fcea' => $this->getFceaStats($currentEvent),
            'species' => $this->getSpeciesStats($currentEvent),
            'users' => $this->getUserStats($currentEvent),
            'timeline' => $this->getTimelineStats($currentEvent),
            'financial' => $this->getFinancialStats($currentEvent),
            'daily' => $this->getDailyStats($currentEvent),
            'currentEvent' => $currentEvent?->name ?? 'No Active Event',
        ];
    }

    private function getOverviewStats(?Event $currentEvent): array
    {
        return [
            'total_users' => User::count(),
            'total_badges' => Badge::count(),
            'total_fursuits' => Fursuit::count(),
            'total_catches' => UserCatch::count(),
            'total_events' => Event::count(),
            'current_event_badges' => $currentEvent ? Badge::whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            })->count() : 0,
            'current_event_participants' => $currentEvent ? EventUser::where('event_id', $currentEvent->id)->count() : 0,
        ];
    }

    private function getEventStats($events): array
    {
        return $events->map(function ($event) {
            $badges = Badge::whereHas('fursuit', function ($q) use ($event) {
                $q->where('event_id', $event->id);
            });
            $fursuits = Fursuit::where('event_id', $event->id);
            $participants = EventUser::where('event_id', $event->id);
            $catches = UserCatch::where('event_id', $event->id);

            return [
                'id' => $event->id,
                'name' => $event->name,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'is_active' => $event->allowsOrders(),
                'badges_count' => $badges->count(),
                'fursuits_count' => $fursuits->count(),
                'participants_count' => $participants->count(),
                'catches_count' => $catches->count(),
                'completion_rate' => $this->calculateCompletionRate($event),
            ];
        })->toArray();
    }

    private function getBadgeStats(?Event $currentEvent): array
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        $badges = $query->get();
        $stateStats = $badges->groupBy('status_payment')->map->count();

        return [
            'total' => $badges->count(),
            'by_state' => $stateStats->toArray(),
            'upgrades' => [
                'double_sided' => $badges->where('dual_side_print', true)->count(),
                'spare_copies' => $badges->where('extra_copy', true)->count(),
            ],
        ];
    }

    private function getFursuitStats(?Event $currentEvent): array
    {
        $query = Fursuit::query();
        if ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        }

        $fursuits = $query->with('species')->get();
        $stateStats = $fursuits->groupBy('status')->map->count();

        // Calculate unique fursuiters by name and species combination, but only if more than one unique
        $uniqueFursuiters = $fursuits
            ->filter(fn ($f) => ! empty($f->name) && $f->species && ! empty($f->species->name))
            ->groupBy(function ($fursuit) {
                return trim(mb_strtolower($fursuit->name)).'|'.trim(mb_strtolower($fursuit->species->name));
            })->count();

        return [
            'total' => $fursuits->count(),
            'unique_fursuiters' => $uniqueFursuiters > 1 ? $uniqueFursuiters : null,
            'by_state' => $stateStats->toArray(),
            'published' => $fursuits->where('published', true)->count(),
            'catch_em_all_enabled' => $fursuits->where('catch_em_all', true)->count(),
            'approval_rate' => $this->calculateApprovalRate($fursuits),
            'top_owners' => $this->getTopFursuitOwners($currentEvent) ?? [],
        ];
    }

    private function getFceaStats(?Event $currentEvent): array
    {
        $query = UserCatch::query();
        if ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        }

        $catches = $query->get();
        $totalPlayers = User::whereHas('fursuitsCatched', function ($q) use ($currentEvent) {
            if ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }
        })->count();

        $catchableFursuits = Fursuit::where('catch_em_all', true);
        if ($currentEvent) {
            $catchableFursuits->where('event_id', $currentEvent->id);
        }

        return [
            'total_catches' => $catches->count(),
            'total_players' => $totalPlayers,
            'catchable_fursuits' => $catchableFursuits->count(),
            'average_catches_per_player' => $totalPlayers > 0 ? round($catches->count() / $totalPlayers, 2) : 0,
            'most_active_day' => $this->getMostActiveCatchDay($currentEvent),
            'top_catchers' => $this->getTopCatchers($currentEvent, 5),
            'most_caught_fursuits' => $this->getMostCaughtFursuits($currentEvent, 5),
            'completion_stats' => $this->getFceaCompletionStats($currentEvent),
        ];
    }

    private function getSpeciesStats(?Event $currentEvent): array
    {
        // Only count fursuits for the current event
        $species = Species::with(['fursuits' => function ($q) use ($currentEvent) {
            if ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }
        }])->get();

        return [
            'total' => $species->count(),
            'most_popular' => $species->map(function ($species) {
                $fursuits = $species->fursuits;
                // Calculate badges count through fursuits relationship
                $badgeCount = $fursuits->load('badges')->pluck('badges')->flatten()->count();

                return [
                    'name' => $species->name,
                    'fursuits_count' => $fursuits->count(),
                    'badges_count' => $badgeCount,
                ];
            })->sortByDesc('fursuits_count')->take(10)->values()->toArray(),
        ];
    }

    private function getUserStats(?Event $currentEvent): array
    {
        $totalUsers = User::count();
        $participatingUsers = 0;
        $averageBadgesPerUser = 0;

        if ($currentEvent) {
            $participatingUsers = EventUser::where('event_id', $currentEvent->id)->count();
            $totalBadges = Badge::whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            })->count();
            $averageBadgesPerUser = $participatingUsers > 0 ? round($totalBadges / $participatingUsers, 2) : 0;
        }

        return [
            'total' => $totalUsers,
            'participating_in_current_event' => $participatingUsers,
            'average_badges_per_user' => $averageBadgesPerUser,
            'new_users_this_month' => User::where('created_at', '>=', now()->subMonth())->count(),
            'most_active_users' => $this->getMostActiveUsers($currentEvent, 5),
        ];
    }

    private function getTimelineStats(?Event $currentEvent): array
    {
        // Show FCEA activity for the current event only
        $dailyStats = UserCatch::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->when($currentEvent, function ($query) use ($currentEvent) {
                $query->where('event_id', $currentEvent->id);
            })
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($stat) {
                return [
                    'date' => $stat->date,
                    'catches' => $stat->count,
                ];
            });

        // Ensure we always return an array, even if empty
        return [
            'daily_catches' => $dailyStats->toArray(),
            'peak_activity' => $this->getPeakCatchActivity($currentEvent) ?? ['peak_hour' => null, 'peak_day' => null],
        ];
    }

    // Helper methods for complex calculations

    private function calculateCompletionRate(Event $event): float
    {
        $totalParticipants = EventUser::where('event_id', $event->id)->count();
        $participantsWithBadges = EventUser::where('event_id', $event->id)
            ->whereHas('user.fursuits.badges', function ($q) use ($event) {
                $q->whereHas('fursuit', function ($subQ) use ($event) {
                    $subQ->where('event_id', $event->id);
                });
            })->count();

        return $totalParticipants > 0 ? round(($participantsWithBadges / $totalParticipants) * 100, 2) : 0;
    }

    private function calculateApprovalRate($fursuits): float
    {
        $total = $fursuits->count();
        $approved = $fursuits->filter(function ($fursuit) {
            return $fursuit->status && method_exists($fursuit->status, 'equals') && $fursuit->status->equals('approved');
        })->count();

        return $total > 0 ? round(($approved / $total) * 100, 2) : 0;
    }

    private function getFursuitsBySpecies(?Event $currentEvent): array
    {
        $query = Fursuit::with('species');
        if ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        }

        return $query->get()
            ->groupBy('species.name')
            ->map->count()
            ->sortDesc()
            ->take(10)
            ->toArray();
    }

    private function getTopFursuitOwners(?Event $currentEvent, int $limit = 5): array
    {
        $query = User::withCount(['fursuits' => function ($q) use ($currentEvent) {
            if ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }
        }]);

        $results = $query->having('fursuits_count', '>', 0)
            ->orderByDesc('fursuits_count')
            ->limit($limit)
            ->get(['name', 'fursuits_count']);

        return $results->map(function ($user) {
            return [
                'name' => $user->name,
                'fursuits_count' => $user->fursuits_count,
            ];
        })->toArray();
    }

    private function getTopCatchers(?Event $currentEvent, int $limit = 5): array
    {
        $query = User::withCount(['fursuitsCatched' => function ($q) use ($currentEvent) {
            if ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }
        }]);

        return $query->having('fursuits_catched_count', '>', 0)
            ->orderByDesc('fursuits_catched_count')
            ->limit($limit)
            ->get(['name', 'fursuits_catched_count'])
            ->map(function ($user) {
                return [
                    'name' => $user->name,
                    'catches' => $user->fursuits_catched_count,
                ];
            })
            ->toArray();
    }

    private function getMostCaughtFursuits(?Event $currentEvent, int $limit = 5): array
    {
        $query = Fursuit::withCount(['catchedByUsers' => function ($q) use ($currentEvent) {
            if ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }
        }])->with(['species', 'user']);

        return $query->having('catched_by_users_count', '>', 0)
            ->orderByDesc('catched_by_users_count')
            ->limit($limit)
            ->get()
            ->map(function ($fursuit) {
                return [
                    'name' => $fursuit->name,
                    'species' => $fursuit->species->name ?? 'Unknown',
                    'owner' => $fursuit->user->name ?? 'Unknown',
                    'catches' => $fursuit->catched_by_users_count,
                ];
            })
            ->toArray();
    }

    private function getFceaCompletionStats(?Event $currentEvent): array
    {
        if (! $currentEvent) {
            return [];
        }

        $totalCatchable = Fursuit::where('event_id', $currentEvent->id)
            ->where('catch_em_all', true)
            ->count();

        if ($totalCatchable === 0) {
            return [];
        }

        $completionRates = User::whereHas('fursuitsCatched', function ($q) use ($currentEvent) {
            $q->where('event_id', $currentEvent->id);
        })
            ->withCount(['fursuitsCatched' => function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }])
            ->get()
            ->map(function ($user) use ($totalCatchable) {
                return round(($user->fursuits_catched_count / $totalCatchable) * 100, 1);
            });

        return [
            'average_completion' => round($completionRates->avg() ?? 0, 2),
            'median_completion' => $completionRates->median(),
            'players_with_100_percent' => $completionRates->filter(fn ($rate) => $rate >= 100)->count(),
            'players_with_50_percent' => $completionRates->filter(fn ($rate) => $rate >= 50)->count(),
        ];
    }

    private function getMostActiveCatchDay(?Event $currentEvent): ?string
    {
        $query = UserCatch::selectRaw('DATE(created_at) as date, COUNT(*) as count');
        if ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        }

        $mostActive = $query->groupBy('date')
            ->orderByDesc('count')
            ->first();

        return $mostActive ? $mostActive->date : null;
    }

    private function getMostActiveUsers(?Event $currentEvent, int $limit = 5): array
    {
        $query = User::withCount(['fursuits' => function ($q) use ($currentEvent) {
            if ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }
        }]);

        return $query->having('fursuits_count', '>', 0)
            ->orderByDesc('fursuits_count')
            ->limit($limit)
            ->get(['name', 'fursuits_count'])
            ->map(function ($user) {
                return [
                    'name' => $user->name,
                    'badges' => $user->fursuits_count, // Actually fursuits, but represents activity
                ];
            })
            ->toArray();
    }

    private function getPeakCatchActivity(?Event $currentEvent): array
    {
        $hourlyStats = UserCatch::selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
            ->when($currentEvent, function ($query) use ($currentEvent) {
                $query->where('event_id', $currentEvent->id);
            })
            ->groupBy('hour')
            ->orderByDesc('count')
            ->first();

        $dayStats = UserCatch::selectRaw('DAYNAME(created_at) as day, COUNT(*) as count')
            ->when($currentEvent, function ($query) use ($currentEvent) {
                $query->where('event_id', $currentEvent->id);
            })
            ->groupBy('day')
            ->orderByDesc('count')
            ->first();

        return [
            'peak_hour' => $hourlyStats ? $hourlyStats->hour.':00' : null,
            'peak_day' => $dayStats ? $dayStats->day : null,
        ];
    }

    private function getFinancialStats(?Event $currentEvent): array
    {
        if (! $currentEvent) {
            return [
                'total_revenue' => 0,
                'prepaid_revenue' => 0,
                'late_badge_revenue' => 0,
                'printing_cost' => null,
                'profit_margin' => null,
                'is_profitable' => null,
            ];
        }

        // Calculate prepaid badge revenue
        $prepaidRevenue = 0;
        $eventUsers = $currentEvent->eventUsers()->where('prepaid_badges', '>', 1)->get();
        foreach ($eventUsers as $eventUser) {
            $paidBadges = $eventUser->prepaid_badges - 1;
            $prepaidRevenue += $paidBadges * 2.00;
        }

        // Calculate late badge revenue
        $lateBadgeRevenue = $currentEvent->badges()
            ->where('apply_late_fee', true)
            ->where('status_payment', 'paid')
            ->count() * 3.00;

        // Calculate total actual revenue from paid badges
        $actualBadgeRevenue = $currentEvent->badges()
            ->where('status_payment', 'paid')
            ->sum('total') / 100;

        $totalRevenue = $prepaidRevenue + $lateBadgeRevenue + $actualBadgeRevenue;

        $profitMargin = $currentEvent->cost ? $totalRevenue - $currentEvent->cost : null;

        return [
            'total_revenue' => round($totalRevenue, 2),
            'prepaid_revenue' => round($prepaidRevenue, 2),
            'late_badge_revenue' => round($lateBadgeRevenue, 2),
            'actual_badge_revenue' => round($actualBadgeRevenue, 2),
            'printing_cost' => $currentEvent->cost ? round(floatval($currentEvent->cost), 2) : null,
            'profit_margin' => $profitMargin ? round($profitMargin, 2) : null,
            'is_profitable' => $profitMargin !== null ? ($profitMargin >= 0) : null,
        ];
    }

    private function getDailyStats(?Event $currentEvent): array
    {
        $today = now()->startOfDay();

        if (! $currentEvent) {
            return [
                'badges_ordered_today' => 0,
                'money_processed_today' => 0,
                'cash_processed_today' => 0,
                'card_processed_today' => 0,
                'badges_picked_up_today' => 0,
                'badges_printed_today' => 0,
            ];
        }

        // Badges ordered today (created today)
        $badgesOrderedToday = $currentEvent->badges()
            ->where('created_at', '>=', $today)
            ->count();

        // Money processed today (paid_at today)
        $moneyProcessedToday = $currentEvent->badges()
            ->where('paid_at', '>=', $today)
            ->where('status_payment', 'paid')
            ->sum('total') / 100;

        // For cash/card breakdown, we'd need to check the transactions table or wallet transfers
        // This is a simplified version - you might need to adjust based on your payment tracking
        $cashProcessedToday = 0; // Would need to query transactions/transfers with payment_method
        $cardProcessedToday = 0; // Would need to query transactions/transfers with payment_method

        // Badges picked up today
        $badgesPickedUpToday = $currentEvent->badges()
            ->where('picked_up_at', '>=', $today)
            ->count();

        // Badges printed today
        $badgesPrintedToday = $currentEvent->badges()
            ->where('printed_at', '>=', $today)
            ->count();

        return [
            'badges_ordered_today' => $badgesOrderedToday,
            'money_processed_today' => round($moneyProcessedToday, 2),
            'cash_processed_today' => round($cashProcessedToday, 2), // Placeholder
            'card_processed_today' => round($cardProcessedToday, 2), // Placeholder
            'badges_picked_up_today' => $badgesPickedUpToday,
            'badges_printed_today' => $badgesPrintedToday,
        ];
    }
}
