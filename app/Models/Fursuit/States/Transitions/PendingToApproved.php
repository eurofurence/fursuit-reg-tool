<?php

namespace App\Models\Fursuit\States\Transitions;

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\User;
use App\Notifications\FursuitApprovedNotification;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class PendingToApproved extends Transition
{
    /**
     * `$notify` is off when a review decision owns the mail.
     *
     * FursuitReviewService queues the attendee's mail against a decision row with a delay,
     * so a reviewer who mis-clicks can undo before anything is sent. The transition
     * mailing immediately would defeat that, so the review path suppresses it here and
     * DeliverFursuitReviewDecisionJob sends the same notification later. Every other
     * caller - the edit form, the legacy panel - keeps the old behaviour.
     */
    public function __construct(public Fursuit $fursuit, public User $reviewer, public bool $notify = true) {}

    public function handle()
    {
        return DB::transaction(function () {
            $this->fursuit->status = new Approved($this->fursuit);
            $this->fursuit->approved_at = now();
            $this->fursuit->rejected_at = null;
            $this->fursuit->save();
            activity()
                ->performedOn($this->fursuit)
                ->causedBy($this->reviewer)
                ->log('Fursuit approved');

            // Only notify if we are reviewing before the event has ended (i.e., notification is still relevant)
            $eventEndsAt = $this->fursuit->event->ends_at ?? null;
            if ($this->notify && $eventEndsAt && now()->lt($eventEndsAt)) {
                $this->fursuit->user->notify(new FursuitApprovedNotification($this->fursuit));
            }

            return $this->fursuit;
        });
    }
}
