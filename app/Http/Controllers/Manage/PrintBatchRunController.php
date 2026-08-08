<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Http\Controllers\Controller;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The four verbs that change a print run: pause, resume, cancel and verify (audit 4.8 row
 * actions 2 to 4, audit 4.8.1 `verify`).
 *
 * They live apart from PrintBatchController for the same reason retry lives apart from
 * PrintJobController: the read controller owns pages and props, and this owns the verbs
 * that reach the hardware and the attendees behind it. Every one is a POST, so nothing
 * halts, restarts or cancels a live convention run as a side effect of a page being opened
 * or a ten-second poll coming round.
 *
 * Each verb re-asks the predicate its button was built with before it writes. Two of the
 * three model methods are not safe to call speculatively, which is why the guard is here
 * rather than left to the return value:
 *
 *  - `PrintBatch::resume()` requeues every failed job in the batch *before* it attempts the
 *    transition, so calling it on a batch that is not Paused rescues cards and then refuses
 *    the state change, leaving the run in a shape nobody asked for.
 *  - `PrintBatch::cancel()` cancels the outstanding jobs and unlocks their badges inside its
 *    transaction and only then attempts the transition, so calling it on a terminal batch
 *    can write before it fails.
 *
 * `pause()` is a bare transition and would refuse cleanly, but it is guarded the same way so
 * the three read alike and none of them can be the one that was left speculative.
 *
 * The refusal toasts are new. The Filament resource reports success for a pause or a resume
 * that silently did nothing, which is the class of silence plan 2.10 #45 exists to end; the
 * cancel failure copy is the resource's own and is reproduced word for word.
 */
class PrintBatchRunController extends Controller
{
    /**
     * `->maxLength(1000)` on both reason inputs.
     *
     * One constant, two places: the validation rules below and the `maxLength` each action
     * field declares, which ActionButton renders as the input's own `maxlength`. Filament's
     * cap stopped the typing rather than rejecting the submission, and an operator at a
     * jammed printer should not meet a 422 after hitting Confirm.
     */
    public const REASON_MAX_LENGTH = 1000;

    /**
     * `->default('Cancelled from the admin panel')` on the cancel form, which is also the
     * fallback the action applies when the field is submitted blank.
     */
    public const DEFAULT_CANCEL_REASON = 'Cancelled from the admin panel';

    /**
     * Halt the run. The reason is required because it is what the person standing at the
     * printer reads.
     */
    public function pause(Request $request, PrintBatch $printBatch): RedirectResponse
    {
        Gate::authorize('pause', $printBatch);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH],
        ]);

        if ($printBatch->status !== PrintBatchStatusEnum::Printing) {
            return $this->refuse('pause', $printBatch);
        }

        $printBatch->pause($validated['reason']);

        // PrintBatchResource's own notification, verbatim: success, title only, no body.
        Toast::flashSuccess('Batch paused');

        return back();
    }

    /**
     * Put the run back to work. `PrintBatch::resume()` also returns every failed card in
     * the batch to the queue, because whoever resumed has just dealt with whatever stopped
     * it; without that the failures blocked the batch from ever completing.
     */
    public function resume(PrintBatch $printBatch): RedirectResponse
    {
        Gate::authorize('resume', $printBatch);

        if ($printBatch->status !== PrintBatchStatusEnum::Paused) {
            return $this->refuse('resume', $printBatch);
        }

        $printBatch->resume();

        Toast::flashSuccess('Batch resumed');

        return back();
    }

    /**
     * Stop the run for good.
     *
     * `PrintBatch::cancel()` pulls every job that has not printed, walks a job that is
     * mid-transfer back to Pending first because a card in the printer is not something
     * that can be un-printed, cancels them all, hands editing back to every attendee whose
     * badge never produced a card, recalculates the counters and moves the batch to
     * Cancelled. Cards that already printed stay printed.
     */
    public function cancel(Request $request, PrintBatch $printBatch): RedirectResponse
    {
        Gate::authorize('cancel', $printBatch);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:'.self::REASON_MAX_LENGTH],
        ]);

        if ($printBatch->status?->isTerminal() ?? true) {
            return $this->refuse('cancel', $printBatch);
        }

        // `$data['reason'] ?: 'Cancelled from the admin panel'` verbatim. An empty box
        // arrives as null rather than '' because ConvertEmptyStringsToNull runs globally,
        // and both fall to the default.
        $printBatch->cancel($validated['reason'] ?: self::DEFAULT_CANCEL_REASON);

        Toast::flashSuccess('Batch cancelled');

        return back();
    }

    /**
     * Record that a human looked at the printed card and it is right.
     *
     * A job reaching Printed only means something claimed it finished. Whether the correct
     * card physically came out is a separate question, and this is the only place in the
     * panel that answers it.
     *
     * The ability is asked of the batch rather than of the job, which is what Filament's
     * relation manager did: a relation manager authorizes against the owner record.
     */
    public function verify(PrintBatch $printBatch, PrintJob $printJob): RedirectResponse
    {
        Gate::authorize('verify', $printBatch);

        // The card has to belong to this run. The pair comes off the URL, so a mismatched
        // id is a 404 rather than a verification recorded against somebody else's batch.
        if ($printJob->print_batch_id !== $printBatch->getKey()) {
            throw new NotFoundHttpException;
        }

        /*
         * The visibility predicate the action was built with, asked again at the write. The
         * card list polls every ten seconds, so a row can move between the poll that drew
         * the button and the confirm modal being submitted.
         */
        if ($printJob->status !== PrintJobStatusEnum::Printed || $printJob->verified_print_at !== null) {
            Toast::flashDanger(
                'Nothing was verified',
                'This card has not printed, or somebody has already confirmed it.',
            );

            return back();
        }

        $printJob->markVerified(PrintVerificationSourceEnum::Operator, auth()->user());

        // PrintJobsRelationManager's own notification, verbatim.
        Toast::flashSuccess('Card verified');

        return back();
    }

    /**
     * The danger toast for a control that arrived after the batch had moved on.
     *
     * `Cannot cancel a batch that is {label}` is the resource's own copy; pause and resume
     * report the same way rather than claiming a success that did not happen.
     */
    private function refuse(string $verb, PrintBatch $printBatch): RedirectResponse
    {
        $label = $printBatch->status?->label() ?? 'in an unknown state';

        Toast::flashDanger("Cannot {$verb} a batch that is {$label}");

        return back();
    }
}
