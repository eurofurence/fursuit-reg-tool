<?php

namespace App\Models\Fursuit\States\Transitions;

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\User;
use App\Notifications\FursuitRejectionReversedNotification;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class RejectedToApproved extends Transition
{
    /** `$notify` is off when a review decision owns the mail; see PendingToApproved. */
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
                ->log('Fursuit approved (was previously rejected)');

            // Always notify when reversing a rejection - this is important for user
            // experience - unless a review decision has taken the mail over.
            if ($this->notify) {
                $this->fursuit->user->notify(new FursuitRejectionReversedNotification($this->fursuit));
            }

            return $this->fursuit;
        });
    }
}
