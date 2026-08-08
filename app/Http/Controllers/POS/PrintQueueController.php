<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use Inertia\Inertia;

class PrintQueueController extends Controller
{
    public function index()
    {
        $printJobs = PrintJob::with(['printable', 'printer'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('POS/PrintQueue/Index', [
            'printJobs' => $printJobs,
        ]);
    }

    public function markAsPrinted(PrintJob $printJob)
    {
        $printJob->update([
            'status' => PrintJobStatusEnum::Printed,
            'printed_at' => now(),
        ]);

        // If this is a badge print job, transition the badge to ReadyForPickup
        if ($printJob->printable_type === Badge::class) {
            $badge = $printJob->printable;
            if ($badge && $badge->status_fulfillment->canTransitionTo(ReadyForPickup::class)) {
                $badge->status_fulfillment->transitionTo(ReadyForPickup::class);
            }
        }

        return redirect()->back()->with('success', 'Print job marked as printed');
    }

    public function retry(PrintJob $printJob)
    {
        // Create a new retry job with printer reassignment (find available printer)
        $retryJob = $printJob->createRetryJob(reassignPrinter: true);

        $message = $retryJob->printer_id === $printJob->printer_id
            ? "Retry job #{$retryJob->id} created on same printer ({$retryJob->printer->name})"
            : "Retry job #{$retryJob->id} reassigned from {$printJob->printer->name} to {$retryJob->printer->name}";

        return redirect()->back()->with('success', $message);
    }

    public function delete(PrintJob $printJob)
    {
        // A batched job is not the operator's to delete. Removing the row left
        // the batch behind with nothing in it: recalculateCounters() wrote
        // total_jobs = 0, cancel() could not unlock the badge because it walks
        // the jobs and there were none, and the empty batch stayed selectable
        // for the agent to pick up again and again.
        //
        // Cancelling the batch is the supported way out, and it releases the
        // badges properly.
        if ($printJob->print_batch_id !== null) {
            return redirect()->back()->with(
                'error',
                'This job belongs to a print batch. Cancel the batch instead, '
                .'which also hands the badges back to their owners.'
            );
        }

        $printJob->delete();

        return redirect()->back()->with('success', 'Print job deleted');
    }
}
