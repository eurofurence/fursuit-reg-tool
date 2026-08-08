<?php

namespace App\Jobs;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Models\Fursuit\FursuitReviewDecision;
use App\Notifications\FursuitApprovedNotification;
use App\Notifications\FursuitPublicationBlockedNotification;
use App\Notifications\FursuitRejectedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Tell the attendee what a reviewer decided - unless the reviewer has taken it back.
 *
 * Dispatched with a delay (FursuitReviewService::UNDO_WINDOW_MINUTES) so a mis-click in a
 * fast queue costs nobody a mail. The check happens when the job wakes up, not when it is
 * queued, and it is deliberately paranoid: three separate things can make the verdict this
 * job carries the wrong thing to send.
 *
 *  - It was undone. Nothing to say.
 *  - A newer verdict exists. That verdict has its own job, and this one would announce a
 *    decision that has already been replaced.
 *  - The fursuit has moved on since. The attendee resubmitting resets the record without
 *    writing a decision, so the verdict describes a submission that no longer exists.
 *
 * Announcing a verdict is idempotent by `notified_at`, which is also what closes the undo
 * window: once this job has run, the correction is a new verdict rather than an erasure.
 */
class DeliverFursuitReviewDecisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $decisionId) {}

    public function handle(): void
    {
        $decision = FursuitReviewDecision::query()
            ->with(['fursuit.user', 'fursuit.event'])
            ->find($this->decisionId);

        if ($decision === null || $decision->undone_at !== null || $decision->notified_at !== null) {
            return;
        }

        // The undo window, enforced where it is stored. The sweeper only queues rows that
        // are due, but a job can also be dispatched by hand or replayed from a failed-jobs
        // table, and sending early is exactly the thing the window exists to prevent.
        if ($decision->notify_at !== null && now()->lt($decision->notify_at)) {
            return;
        }

        if (! $decision->isCurrent()) {
            return;
        }

        $fursuit = $decision->fursuit;
        $user = $fursuit?->user;

        if ($fursuit === null || $user === null) {
            return;
        }

        // The verdict has to still describe the record. `status` is the part of it that
        // can move without a decision row: the attendee resubmitting resets the fursuit
        // to pending, and telling them their old submission was approved after that is
        // worse than telling them nothing.
        $expected = match ($decision->outcome) {
            FursuitReviewOutcomeEnum::Rejected => 'rejected',
            default => 'approved',
        };

        if ($expected !== $fursuit->status::$name) {
            Log::info('fursuit review notification skipped: the record moved on', [
                'decision_id' => $decision->id,
                'fursuit_id' => $fursuit->id,
                'outcome' => $decision->outcome->value,
                'status' => $fursuit->status::$name,
            ]);

            return;
        }

        // Same rule the transitions have always applied: after the event, an approval or
        // rejection mail is noise. A publication block is not urgent either.
        $eventEndsAt = $fursuit->event?->ends_at;

        if ($eventEndsAt === null || now()->gte($eventEndsAt)) {
            $decision->forceFill(['notified_at' => now()])->save();

            return;
        }

        $user->notify(match ($decision->outcome) {
            FursuitReviewOutcomeEnum::Approved => new FursuitApprovedNotification($fursuit),
            FursuitReviewOutcomeEnum::Rejected => new FursuitRejectedNotification($fursuit, (string) $decision->reason),
            FursuitReviewOutcomeEnum::PublicationBlocked => new FursuitPublicationBlockedNotification($fursuit, (string) $decision->reason),
        });

        $decision->forceFill(['notified_at' => now()])->save();
    }
}
