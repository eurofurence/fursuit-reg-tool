<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Models\PrintJob;
use App\Http\Controllers\Controller;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The one endpoint in this module that puts a card back through a printer (audit 4.9,
 * row action 3).
 *
 * It lives apart from PrintJobController for the same reason the fursuit moderation verbs
 * do: the CRUD controller owns pages and props, and this owns a hardware-facing verb. A
 * POST, never a GET, so nothing re-queues a job as a side effect of a page being opened
 * or a poll coming round, and gated on its own `retry` ability rather than on `update`.
 *
 * `createRetryJob(reassignPrinter: true)` makes a **new** Pending job carrying
 * `retry_of`, the same batch, sequence, printable and file, on an available printer of
 * the same type. The original stays Failed and its batch stays Paused - which is the
 * behaviour today and a recorded gap (audit 85): a retried card does not start printing
 * until somebody also resumes the batch from the batch page, which lands in phase 7. The
 * toast says which job was created so the operator can find it.
 */
class PrintJobRetryController extends Controller
{
    public function store(PrintJob $printJob): RedirectResponse
    {
        Gate::authorize('retry', $printJob);

        /*
         * The visibility predicate the action was built with, asked again at the write.
         * A list polling every five seconds can offer Retry on a row that has already
         * moved by the time the confirm modal is submitted.
         */
        if (! $printJob->canRetry()) {
            Toast::flashDanger(
                'Nothing was retried',
                'This print job is not failed, or it has already been retried three times.',
            );

            return back();
        }

        /*
         * findAvailablePrinter() reads the original printer's type, so a job whose
         * printer row is gone keeps the printer id it has rather than taking the page
         * down. `printer_id` is a constrained foreign key, so this is belt and braces.
         */
        $retryJob = $printJob->createRetryJob(reassignPrinter: $printJob->printer !== null);

        // A new job in the batch changes total_jobs. Nothing in createRetryJob() tells
        // the batch that (plan 2.10 #11 covers deletes; this is the same arithmetic).
        $printJob->batch?->recalculateCounters();

        // PrintJobResource's own notification, verbatim: success, title only, no body.
        Toast::flashSuccess("Created retry job #{$retryJob->id}");

        return back();
    }
}
