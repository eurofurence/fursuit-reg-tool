<?php

namespace App\Http\Controllers\POS\Printing;

use App\Domain\Printing\Services\BadgePrintQueue;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;

class PrintBadgeController extends Controller
{
    public function __invoke(Badge $badge)
    {
        $batch = BadgePrintQueue::queue(
            badges: collect([$badge]),
            createdById: auth()->id(),
            // The POS signs a clerk in on the machine-user guard, so auth()->id()
            // is null here. Without this the run belongs to nobody and never
            // reaches the clerk's own print list.
            createdByStaffId: auth('machine-user')->id(),
        );

        if ($batch === null) {
            return redirect()->back()->with('error', 'Badge could not be queued for printing');
        }

        return redirect()->back()->with('success', 'Badge has been added to the print queue');
    }
}
