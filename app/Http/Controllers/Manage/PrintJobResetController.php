<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Put a selection of print jobs back to Pending: the repair for a run that stopped moving.
 *
 * Cards get stranded in ways nothing else here can clear. An agent claims a job and the
 * Windows host reboots, so the job sits Queued against a machine that will never report
 * back; `printing:reap-leases` handles that on its own, but only once the lease expires
 * and only up to `--max-attempts`. A card fails, the batch pauses, and the failure is the
 * printer rather than the job - Retry makes a *new* job carrying `retry_of`, which is the
 * right shape for one card and the wrong shape for twenty. This is the blunt instrument
 * for the rest: hand it a selection and every one of them is claimable again, in place,
 * keeping its sequence and its batch.
 *
 * It lives apart from PrintJobController for the same reason PrintJobRetryController does:
 * it is a hardware-facing verb, not a page, and a Pending job is one an agent will pick up
 * and print. A POST, so nothing resets a run by being opened or polled.
 *
 * **All or nothing, in one transaction**. A selection containing a card that
 * has already printed resets nothing at all. Half a run moved back to Pending is worse
 * than none of it: the operator has no way of telling which half, and the ones that did
 * move will print again.
 */
class PrintJobResetController extends Controller
{
    private const NOTHING_RESET = 'Nothing was reset';

    public function bulk(Request $request): RedirectResponse
    {
        Gate::authorize('retry', new PrintJob);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $jobs = PrintJob::whereIn('id', $validated['ids'])->get();

        if ($jobs->isEmpty()) {
            Toast::flashDanger(self::NOTHING_RESET, 'None of the selected print jobs still exist.');

            return back();
        }

        foreach ($jobs as $job) {
            if (Gate::denies('retry', $job)) {
                Toast::flashDanger(
                    self::NOTHING_RESET,
                    'You are not allowed to reset one or more of the selected print jobs.',
                );

                return back();
            }

            // A printed card exists in the world and a cancelled one was stopped on
            // purpose. Neither is something to quietly queue again.
            if ($job->status->isTerminal()) {
                Toast::flashDanger(
                    self::NOTHING_RESET,
                    'One or more of the selected print jobs has already printed or been cancelled. Reset only applies to jobs that have not finished.',
                );

                return back();
            }
        }

        $reset = DB::transaction(function () use ($jobs) {
            $reset = 0;

            foreach ($jobs as $job) {
                if ($this->toPending($job)) {
                    $reset++;
                }
            }

            PrintBatch::whereIn('id', $jobs->pluck('print_batch_id')->filter()->unique())
                ->get()
                ->each(fn (PrintBatch $batch) => $batch->recalculateCounters());

            return $reset;
        });

        Log::info('print jobs reset to pending', [
            'job_ids' => $jobs->pluck('id')->all(),
            'reset' => $reset,
            'by_user_id' => auth()->id(),
        ]);

        Toast::flashSuccess(
            $reset === 1 ? '1 print job reset to pending' : "{$reset} print jobs reset to pending",
            $this->pausedBatchNote($jobs),
        );

        return back();
    }

    /**
     * Walk one job back to Pending by whichever route its current status allows.
     *
     * Every path ends in the same place and each clears what its own status left behind,
     * so a reset job carries no stale lease, no dead machine and no old error text.
     * Already-Pending jobs are the no-op the count leaves out: a selection that includes
     * one is not a mistake, it just has nothing to do.
     */
    private function toPending(PrintJob $job): bool
    {
        return match ($job->status) {
            PrintJobStatusEnum::Pending => false,

            // Claimed or mid-card: the lease is the thing to drop, and releaseLease()
            // clears the machine and the timestamps with it.
            PrintJobStatusEnum::Queued,
            PrintJobStatusEnum::Printing => $job->releaseLease('Reset to pending by an operator'),

            // requeue() is the one path that also clears the failure itself: the error
            // text, failed_at and the attempt count that would otherwise fail it again
            // straight away.
            PrintJobStatusEnum::Failed => $job->requeue(),

            // Retrying is a staging state on the way back to the queue; it has no lease
            // to drop, so it is walked through the edges the enum allows.
            PrintJobStatusEnum::Retrying => $job->transitionTo(PrintJobStatusEnum::Queued)
                && $job->releaseLease('Reset to pending by an operator'),

            default => false,
        };
    }

    /**
     * The one thing a reset does not do, said out loud.
     *
     * A batch pauses when a card in it fails, and resetting the card does not un-pause it:
     * only the batch page can, because resuming is the operator saying they have dealt
     * with whatever stopped the run. Without this line the jobs read Pending, the printer
     * stays idle, and there is nothing on screen connecting the two.
     *
     * @param  Collection<int, PrintJob>  $jobs
     */
    private function pausedBatchNote(Collection $jobs): ?string
    {
        $stalled = PrintBatch::whereIn('id', $jobs->pluck('print_batch_id')->filter()->unique())
            ->whereIn('status', [PrintBatchStatusEnum::Paused, PrintBatchStatusEnum::Draft])
            ->pluck('name', 'id');

        if ($stalled->isEmpty()) {
            return null;
        }

        return 'Their run is not printing right now ('.$stalled->values()->join(', ').'). Resume it from the batch page to send these cards.';
    }
}
