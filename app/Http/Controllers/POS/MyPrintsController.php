<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Services\DeskPrintNotifications;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The runs this desk clerk started.
 *
 * Deliberately not the print queue: that page is every job on every printer,
 * which is what a print operator needs and exactly what a clerk with one waiting
 * attendee has to wade through today.
 */
class MyPrintsController extends Controller
{
    public function index(): Response
    {
        $staffId = auth('machine-user')->id();

        return Inertia::render('POS/MyPrints/Index', [
            'batches' => DeskPrintNotifications::recentFor($staffId),
        ]);
    }

    /**
     * Acknowledge a run so it stops sitting on the dashboard.
     *
     * Only the clerk who started it may dismiss it: the notification is theirs,
     * and clearing somebody else's would take the news off a screen they are
     * still waiting on.
     */
    public function dismiss(PrintBatch $printBatch): RedirectResponse
    {
        $staffId = auth('machine-user')->id();

        if ($printBatch->created_by_staff_id !== $staffId) {
            return back()->with('error', 'That print run belongs to another staff member');
        }

        $printBatch->dismissForDesk();

        return back();
    }

    /**
     * Clear everything currently shouting at this clerk.
     */
    public function dismissAll(): RedirectResponse
    {
        $staffId = auth('machine-user')->id();

        PrintBatch::query()
            ->startedByStaff($staffId)
            ->needingDeskAttention()
            ->get()
            ->each(fn (PrintBatch $batch) => $batch->dismissForDesk());

        return back();
    }
}
