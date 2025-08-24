<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class BadgeController extends Controller
{
    public function printBulk(Request $request)
    {
        $badgeIds = $request->input('badge_ids');
        $printerId = $request->input('printer_id');
        
        // If no specific badge IDs provided, get the first 50 unprinted badges with lowest attendee_id
        if (empty($badgeIds)) {
            $currentEvent = \App\Models\Event::latest('starts_at')->first();
            
            if (!$currentEvent) {
                return back()->with('error', 'No current event found');
            }
            
            // Get up to 50 unprinted badges with the lowest attendee IDs
            $badges = Badge::whereHas('fursuit', function ($query) use ($currentEvent) {
                    $query->where('event_id', $currentEvent->id);
                })
                ->where('status_fulfillment', 'pending')
                ->join('fursuits', 'badges.fursuit_id', '=', 'fursuits.id')
                ->leftJoin('event_users', function ($join) use ($currentEvent) {
                    $join->on('fursuits.user_id', '=', 'event_users.user_id')
                         ->where('event_users.event_id', '=', $currentEvent->id);
                })
                ->select('badges.*')
                ->orderByRaw('CAST(event_users.attendee_id AS UNSIGNED) ASC')
                ->limit(50)
                ->get();
        } else {
            $badges = Badge::whereIn('id', $badgeIds)->get();
        }

        if ($badges->isEmpty()) {
            return back()->with('error', 'No badges found to print');
        }

        // Sort badges by attendee ID for consistent printing order (if not already sorted)
        if (!empty($badgeIds)) {
            $sortedBadges = $badges->sortBy(function ($badge) {
                return $badge->fursuit?->user?->eventUsers?->where('event_id', $badge->fursuit->event_id)->first()?->attendee_id ?? 999999;
            });
        } else {
            $sortedBadges = $badges; // Already sorted by the query
        }

        // Update badge states to mark them as sent for printing
        $printedCount = 0;
        $sortedBadges->each(function ($badge) use (&$printedCount) {
            if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
                $badge->status_fulfillment->transitionTo(Processing::class);
                $printedCount++;
            }
        });

        if ($printedCount === 0) {
            return back()->with('error', 'No badges could be printed - all are in wrong state');
        }

        // Create individual print jobs for batching in the correct order
        $printJobs = $sortedBadges->map(function ($badge) use ($printerId) {
            return new PrintBadgeJob($badge, $printerId);
        })->toArray();

        // Create a Laravel batch with proper chaining
        Bus::batch([
            $printJobs
        ])
            ->name("POS Badge Bulk Print - {$printedCount} badges")
            ->onQueue('batch-print')
            ->allowFailures()
            ->dispatch();

        return back()->with('success', "{$printedCount} badge(s) have been added to the print queue");
    }

    public function handoutBulk(Request $request)
    {
        $someError = false;
        $badgeIds = $request->input('badge_ids');
        $badges = Badge::whereIn('id', $badgeIds)->get();
        $badges->each(function ($badge) {
            if ($badge->status_fulfillment->canTransitionTo(PickedUp::class)) {
                $badge->status_fulfillment->transitionTo(PickedUp::class);
            } else {
                $someError = true;
            }
        });
        if ($someError) {
            return back()->with('error', 'Some badges could not be handed out');
        }

        return back()->with('success', 'All Badges handed out successfully');
    }

    public function handout(Badge $badge)
    {
        if ($badge->status_fulfillment->canTransitionTo(PickedUp::class)) {
            $badge->status_fulfillment->transitionTo(PickedUp::class);

            return back()->with('success', 'Badge handed out');
        } else {
            return back()->with('error', 'Badge cannot be handed out');
        }
    }

    public function handoutUndo(Badge $badge)
    {
        if ($badge->status_fulfillment->canTransitionTo(ReadyForPickup::class)) {
            $badge->status_fulfillment->transitionTo(ReadyForPickup::class);

            return back()->with('success', 'Badge handout undone');
        }

        return back()->with('error', 'Badge handout cannot be undone');
    }
}
