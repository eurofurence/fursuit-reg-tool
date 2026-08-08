<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use App\Jobs\CreateReceiptFromCheckoutJob;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * The one write the panel makes on the checkout side: queue this checkout's receipt for
 * the receipt printer.
 *
 * It lives apart from CheckoutController for the same reason PrintJobRetryController does:
 * the CRUD controller owns pages and props, and this owns a hardware-facing verb. A POST,
 * never a GET, so nothing sends paper as a side effect of a page being opened or a link
 * being followed.
 *
 * One method, not two. In the old panel the body is written out twice, byte for byte, once on
 * the list action and once on ViewCheckout, so every fix had to be applied in both places
 * and the raw-string type below was wrong in both.
 *
 * Four things change from the old panel body, all of them plan decisions.
 *
 *  - The type is `PrintJobTypeEnum::Receipt`, and the printer lookup asks for the same
 *    enum. the old panel wrote `'type' => 'receipt'` as a raw string in the same array where
 *    `status` used `PrintJobStatusEnum::Pending`, and looked the printer up with
 *    `where('type', 'receipt')`. `Printer::$casts` already casts the column to the enum,
 *    so the string worked only by coincidence of the enum's own value.
 *  - Rendering is queued, not `dispatchSync`. mPDF and the Fiskaly QR ran inside the web
 *    request, so any failure there was a 500 instead of a toast.
 *  - It is idempotent per checkout, in both halves. The PDF is rendered only when it is
 *    not on disk already, and a checkout that still has an outstanding receipt job does
 *    not get a second one. the old panel re-rendered and re-queued on every click, which is how
 *    one fiscal record could be spammed into a stack of identical receipts.
 *  - Every queue attempt is written to the activity log against the checkout, with the
 *    operator as the causer. There was no log entry at all.
 *
 * The notification copy is untouched: both titles and both bodies are the old panel strings
 * verbatim, including the `#{id}` in the success body.
 *
 * What this does NOT do is mutate the checkout. Not a column, not a state, not a
 * timestamp. The record is fiscal; the only row this endpoint creates is a print job.
 *
 * Known ordering caveat, carried over rather than introduced: the print job is created
 * pointing at `checkouts/{id}.pdf` whether or not the render has finished, exactly as the
 * the old panel body did with its hardcoded path. Queueing the render moves the mPDF failure
 * out of the request but does not order the two, so an agent that claims the job before
 * the render lands sees no file. Ordering them needs a job that owns both halves, which is
 * a new write path into the printing domain and belongs to that module, not here.
 */
class CheckoutReceiptPrintController extends Controller
{
    /**
     * Where CreateReceiptFromCheckoutJob writes, and therefore what the print job points
     * at. Kept as one expression so the two cannot drift.
     */
    public static function receiptPath(Checkout $checkout): string
    {
        return 'checkouts/'.$checkout->id.'.pdf';
    }

    public function store(Request $request, Checkout $checkout): RedirectResponse
    {
        Gate::authorize('printReceipt', $checkout);

        // the old panel looked the printer up with the raw string. Same question, asked with
        // the enum the column is cast to.
        $receiptPrinter = Printer::where('is_active', true)
            ->where('type', PrintJobTypeEnum::Receipt)
            ->first();

        if (! $receiptPrinter) {
            // the old checkout list's danger notification, verbatim.
            Toast::flashDanger(
                'No receipt printer found',
                'Please configure an active receipt printer first.',
            );

            return back();
        }

        /*
         * Render only what is not rendered. The PDF is a pure function of a record that
         * can no longer change, so a second render would write the same bytes over the
         * same path and burn an mPDF run doing it.
         */
        if (! Storage::exists(self::receiptPath($checkout))) {
            CreateReceiptFromCheckoutJob::dispatch($checkout);
        }

        $outstanding = $this->outstandingReceiptJob($checkout);

        if ($outstanding === null) {
            $checkout->printJobs()->create([
                'printer_id' => $receiptPrinter->id,
                'type' => PrintJobTypeEnum::Receipt,
                'file' => self::receiptPath($checkout),
                'status' => PrintJobStatusEnum::Pending,
            ]);
        }

        /*
         * Logged either way, because "somebody asked for this receipt again" is the thing
         * worth knowing on a fiscal record, not just "a row was inserted".
         */
        activity()
            ->performedOn($checkout)
            ->causedBy($request->user())
            ->withProperties([
                'printer_id' => $receiptPrinter->id,
                'duplicate' => $outstanding !== null,
            ])
            ->log('Receipt queued for printing');

        // the old checkout list's success notification, verbatim, including the `#`.
        Toast::flashSuccess(
            'Receipt added to print queue',
            "Receipt for checkout #{$checkout->id} has been queued for printing.",
        );

        return back();
    }

    /**
     * A receipt job for this checkout that has not finished yet.
     *
     * Pending, Queued, Printing and Retrying all mean "this receipt is on its way", so a
     * second click adds nothing but paper. A Printed, Failed or Cancelled job is done with,
     * and asking again is a real request: an operator reprinting a receipt the attendee
     * lost, or retrying one the printer ate.
     */
    private function outstandingReceiptJob(Checkout $checkout): ?PrintJob
    {
        return $checkout->printJobs()
            ->where('type', PrintJobTypeEnum::Receipt)
            ->whereIn('status', [
                PrintJobStatusEnum::Pending->value,
                PrintJobStatusEnum::Queued->value,
                PrintJobStatusEnum::Printing->value,
                PrintJobStatusEnum::Retrying->value,
            ])
            ->first();
    }
}
