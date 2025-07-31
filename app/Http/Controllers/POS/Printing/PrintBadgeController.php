<?php

namespace App\Http\Controllers\POS\Printing;


use App\Http\Controllers\Controller;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Printed;

class PrintBadgeController extends Controller
{
    public function __invoke(Badge $badge)
    {
        PrintBadgeJob::dispatch($badge);
        if($badge->status_fulfillment->canTransitionTo(Printed::class)) {
            $badge->status_fulfillment->transitionTo(Printed::class);
        }
        // dispatch print job
        return redirect()->back()->with('success', 'Badge has been added to the print queue');
    }
}
