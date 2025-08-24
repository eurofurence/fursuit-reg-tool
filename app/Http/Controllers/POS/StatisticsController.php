<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\PrintJob;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class StatisticsController extends Controller
{
    public function index()
    {
        // Cache statistics for 5 minutes for POS system
        $statistics = Cache::remember('pos_statistics', 300, function () {
            return $this->generatePosStatistics();
        });

        return Inertia::render('POS/Statistics/Index', $statistics);
    }

    private function generatePosStatistics(): array
    {
        $currentEvent = Event::getActiveEvent();

        return [
            'overview' => $this->getOverviewStats($currentEvent),
            'badges' => $this->getBadgeStats($currentEvent),
            'printing' => $this->getPrintingStats($currentEvent),
            'sales' => $this->getSalesStats($currentEvent),
            'financial' => $this->getFinancialStats($currentEvent),
            'daily' => $this->getDailyStats($currentEvent),
            'currentEvent' => $currentEvent,
        ];
    }

    private function getOverviewStats(?Event $currentEvent): array
    {
        return [
            'badges_ordered_today' => $this->getBadgesCreatedToday($currentEvent),
            'badges_printed_today' => $this->getBadgesPrintedToday($currentEvent),
            'badges_picked_up_today' => $this->getBadgesHandedOutToday($currentEvent),
            'money_processed_today' => $this->getMoneyProcessedToday($currentEvent),
            'cash_processed_today' => $this->getCashProcessedToday($currentEvent),
            'card_processed_today' => $this->getCardProcessedToday($currentEvent),
            'pending_print_jobs' => $this->getPendingPrintJobs(),
            'participants_registered' => $currentEvent ? EventUser::where('event_id', $currentEvent->id)->count() : 0,
        ];
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

        return [
            'total' => $badges->count(),
            'by_payment_status' => [
                'paid' => $badges->where('status_payment', 'paid')->count(),
                'unpaid' => $badges->where('status_payment', 'unpaid')->count(),
            ],
            'by_fulfillment_status' => [
                'pending' => $badges->where('status_fulfillment', 'pending')->count(),
                'printed' => $badges->where('status_fulfillment', 'printed')->count(),
                'ready_for_pickup' => $badges->where('status_fulfillment', 'ready_for_pickup')->count(),
                'picked_up' => $badges->where('status_fulfillment', 'picked_up')->count(),
            ],
            'upgrades' => [
                'double_sided' => $badges->where('dual_side_print', true)->count(),
                'extra_copies' => $badges->where('extra_copy', true)->count(),
            ],
        ];
    }

    private function getPrintingStats(?Event $currentEvent): array
    {
        $printJobs = PrintJob::with('printable');

        if ($currentEvent) {
            $printJobs->whereHasMorph('printable', [Badge::class], function ($q) use ($currentEvent) {
                $q->whereHas('fursuit', function ($subQ) use ($currentEvent) {
                    $subQ->where('event_id', $currentEvent->id);
                });
            });
        }

        $jobs = $printJobs->get();

        return [
            'total_jobs' => $jobs->count(),
            'pending_jobs' => $jobs->where('status', 'pending')->count(),
            'printed_jobs' => $jobs->where('status', 'printed')->count(),
            'jobs_today' => $jobs->where('created_at', '>=', now()->startOfDay())->count(),
            'average_print_time' => $this->calculateAveragePrintTime($jobs),
            'by_type' => [
                'badge' => $jobs->where('type', 'badge')->count(),
                'receipt' => $jobs->where('type', 'receipt')->count(),
            ],
        ];
    }

    private function getSalesStats(?Event $currentEvent): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        $badges = $query->where('status_payment', 'paid')->get();
        $todayBadges = $badges->where('updated_at', '>=', $todayStart);

        return [
            'total_revenue' => $badges->sum('total'),
            'today_revenue' => $todayBadges->sum('total'),
            'average_order_value' => $badges->count() > 0 ? round($badges->sum('total') / $badges->count()) : 0,
            'transactions_today' => $todayBadges->count(),
            'hourly_sales' => $this->getHourlySales($currentEvent),
        ];
    }

    private function getDailyStats(?Event $currentEvent): array
    {
        if (!$currentEvent || !$currentEvent->starts_at || !$currentEvent->ends_at) {
            return ['event_days' => []];
        }

        $eventDays = collect();
        $startDate = $currentEvent->starts_at->copy();
        $endDate = $currentEvent->ends_at->copy();

        while ($startDate->lte($endDate)) {
            $dayStart = $startDate->copy()->startOfDay();
            $dayEnd = $startDate->copy()->endOfDay();

            $query = Badge::query();
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });

            $dayBadges = $query->whereBetween('created_at', [$dayStart, $dayEnd])->get();
            $paidBadges = $dayBadges->where('status_payment', 'paid');

            $eventDays->push([
                'date' => $startDate->format('Y-m-d'),
                'day_name' => $startDate->format('D'),
                'badges_created' => $dayBadges->count(),
                'badges_paid' => $paidBadges->count(),
                'revenue' => $paidBadges->sum('total'),
                'print_jobs' => PrintJob::whereBetween('created_at', [$dayStart, $dayEnd])->count(),
            ]);

            $startDate->addDay();
        }

        return [
            'event_days' => $eventDays->toArray(),
        ];
    }

    // Helper methods

    private function getBadgesCreatedToday(?Event $currentEvent): int
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query->whereDate('created_at', today())->count();
    }

    private function getBadgesPrintedToday(?Event $currentEvent): int
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query->whereDate('printed_at', today())->count();
    }

    private function getBadgesHandedOutToday(?Event $currentEvent): int
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query->where('status_fulfillment', 'picked_up')
            ->whereDate('updated_at', today())
            ->count();
    }

    private function getTotalSalesToday(?Event $currentEvent): int
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query->where('status_payment', 'paid')
            ->whereDate('updated_at', today())
            ->sum('total');
    }

    private function getPendingPrintJobs(): int
    {
        return PrintJob::where('status', 'pending')->count();
    }

    private function calculateAveragePrintTime($jobs)
    {
        $printedJobs = $jobs->where('status', 'printed')->where('printed_at', '!=', null);

        if ($printedJobs->isEmpty()) {
            return null;
        }

        $totalSeconds = $printedJobs->map(function ($job) {
            return $job->printed_at->diffInSeconds($job->created_at);
        })->avg();

        return round($totalSeconds / 60, 1); // Return in minutes
    }

    private function getHourlySales(?Event $currentEvent): array
    {
        $hours = collect();

        for ($hour = 0; $hour < 24; $hour++) {
            $hourStart = now()->startOfDay()->addHours($hour);
            $hourEnd = $hourStart->copy()->addHour();

            $query = Badge::query();
            if ($currentEvent) {
                $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                    $q->where('event_id', $currentEvent->id);
                });
            }

            $hourlyRevenue = $query->where('status_payment', 'paid')
                ->whereBetween('updated_at', [$hourStart, $hourEnd])
                ->sum('total');

            $hours->push([
                'hour' => $hour,
                'revenue' => $hourlyRevenue,
            ]);
        }

        return $hours->toArray();
    }

    private function getFinancialStats(?Event $currentEvent): array
    {
        if (! $currentEvent) {
            return [
                'total_revenue' => 0,
                'prepaid_badge_revenue' => 0,
                'late_badge_revenue' => 0,
                'actual_badge_revenue' => 0,
                'printing_cost' => null,
                'profit_margin' => null,
                'is_profitable' => null,
                'revenue_breakdown' => [],
            ];
        }

        // Calculate prepaid badge revenue (each prepaid badge beyond 1 costs €2.00)
        $prepaidRevenue = 0;
        $eventUsers = $currentEvent->eventUsers()->where('prepaid_badges', '>', 1)->get();
        foreach ($eventUsers as $eventUser) {
            $paidBadges = $eventUser->prepaid_badges - 1;
            $prepaidRevenue += $paidBadges * 2.00;
        }

        // Calculate late badge revenue (badges with €3.00 total)
        $lateBadgeRevenue = $currentEvent->badges()
            ->where('total', 300) // 300 cents = €3.00
            ->where('status_payment', 'paid')
            ->sum('total') / 100; // Convert cents to euros

        // Calculate POS badge revenue (all paid badges)
        $posBadgeRevenue = $currentEvent->badges()
            ->where('status_payment', 'paid')
            ->sum('total') / 100;

        // Total revenue = prepaid badges + late badges
        $totalRevenue = $prepaidRevenue + $lateBadgeRevenue;
        
        // Actual revenue = POS badge sales + prepaid badges
        $actualRevenue = $posBadgeRevenue + $prepaidRevenue;
        $profitMargin = $currentEvent->cost ? $totalRevenue - $currentEvent->cost : null;
        $moneyNeeded = $currentEvent->cost && $totalRevenue < $currentEvent->cost ? $currentEvent->cost - $totalRevenue : 0;

        return [
            'total_revenue' => round($totalRevenue, 2),
            'actual_revenue' => round($actualRevenue, 2),
            'prepaid_badge_revenue' => round($prepaidRevenue, 2),
            'late_badge_revenue' => round($lateBadgeRevenue, 2),
            'pos_badge_revenue' => round($posBadgeRevenue, 2),
            'printing_cost' => $currentEvent->cost ? round(floatval($currentEvent->cost), 2) : null,
            'profit_margin' => $profitMargin ? round($profitMargin, 2) : null,
            'money_needed_to_cover' => round($moneyNeeded, 2),
            'is_profitable' => $profitMargin !== null ? ($profitMargin >= 0) : null,
            'revenue_breakdown' => [
                'Free badges (1 per user)' => $currentEvent->eventUsers()->count(),
                'Prepaid badges (€2.00 each)' => ($eventUsers->sum('prepaid_badges') - $eventUsers->count()),
                'Late badges (€3.00 each)' => $currentEvent->badges()->where('total', 300)->where('status_payment', 'paid')->count(),
                'All POS badges' => $currentEvent->badges()->where('status_payment', 'paid')->count(),
            ],
        ];
    }

    private function getMoneyProcessedToday(?Event $currentEvent): float
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query->where('status_payment', 'paid')
            ->whereDate('paid_at', today())
            ->sum('total') / 100;
    }

    private function getCashProcessedToday(?Event $currentEvent): float
    {
        // This would need to query checkouts or transactions to get cash vs card breakdown
        // For now, returning 0 as placeholder - would need to check checkout records
        // with payment method tracking
        return 0;
    }

    private function getCardProcessedToday(?Event $currentEvent): float
    {
        // This would need to query checkouts or transactions to get cash vs card breakdown
        // For now, returning 0 as placeholder - would need to check checkout records
        // with payment method tracking
        return 0;
    }
}
