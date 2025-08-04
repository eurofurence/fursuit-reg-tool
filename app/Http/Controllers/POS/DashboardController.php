<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Domain\Printing\Models\PrintJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $currentEvent = Event::getActiveEvent();
        
        // Get real-time stats
        $stats = [
            'badges_today' => $this->getBadgesToday($currentEvent),
            'pending_print' => $this->getPendingPrintJobs(),
            'todays_sales' => $this->getTodaysSales($currentEvent),
        ];

        return Inertia::render('POS/Dashboard', [
            'stats' => $stats,
        ]);
    }

    private function getBadgesToday(?Event $currentEvent): int
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query->whereDate('created_at', today())->count();
    }

    private function getPendingPrintJobs(): int
    {
        return PrintJob::where('status', 'pending')->count();
    }

    private function getTodaysSales(?Event $currentEvent): int
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query->where('status_payment', 'paid')
            ->whereDate('updated_at', today())
            ->sum('total');
    }
}
