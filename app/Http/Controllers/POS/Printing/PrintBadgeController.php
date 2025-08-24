<?php

namespace App\Http\Controllers\POS\Printing;

use App\Http\Controllers\Controller;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Processing;

class PrintBadgeController extends Controller
{
    public function __invoke(Badge $badge)
    {
        if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
            $badge->status_fulfillment->transitionTo(Processing::class);
        }
        PrintBadgeJob::dispatch($badge);

        // dispatch print job
        return redirect()->back()->with('success', 'Badge has been added to the print queue');
    }
}
