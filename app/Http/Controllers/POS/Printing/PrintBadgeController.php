<?php

namespace App\Http\Controllers\POS\Printing;

use App\Badges\EF28_Badge;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Badge\States\Printed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrintBadgeController extends Controller
{
    public function __invoke(Badge $badge)
    {
        PrintBadgeJob::dispatch($badge);
        if($badge->status !== Printed::class && $badge->status->canTransitionTo(Printed::class)) {
            $badge->status->transitionTo(Printed::class);
        }
        // dispatch print job
        return redirect()->back()->with('success', 'Badge has been added to the print queue');
    }
}
