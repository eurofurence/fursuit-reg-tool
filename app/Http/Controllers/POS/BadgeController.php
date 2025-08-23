<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    public function printBulk(Request $request)
    {
        $badgeIds = $request->input('badge_ids');
        $printerId = $request->input('printer_id');
        $badges = Badge::whereIn('id', $badgeIds)->get();

        $printedCount = 0;
        $badges->each(function ($badge, $index) use (&$printedCount, $printerId) {
            if ($badge->status_fulfillment->canTransitionTo(Printed::class)) {
                $badge->status_fulfillment->transitionTo(Printed::class);
                $printedCount++;
            }

            if ($printerId) {
                // Create print job directly with specific printer
                \App\Domain\Printing\Models\PrintJob::create([
                    'printer_id' => $printerId,
                    'printable_type' => Badge::class,
                    'printable_id' => $badge->id,
                    'type' => \App\Enum\PrintJobTypeEnum::Badge,
                    'status' => \App\Enum\PrintJobStatusEnum::Pending,
                    'priority' => 1,
                ]);
            } else {
                // Use default PrintBadgeJob dispatch
                PrintBadgeJob::dispatch($badge)->delay(now()->addSeconds($index * 15));
            }
        });

        if ($printedCount === 0) {
            return back()->with('error', 'No badges could be printed');
        }

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
