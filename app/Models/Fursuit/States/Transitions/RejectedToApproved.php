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
    public function __construct(public Fursuit $fursuit, public User $reviewer) {}

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

            // Always notify when reversing a rejection - this is important for user experience
            $this->fursuit->user->notify(new FursuitRejectionReversedNotification($this->fursuit));

            return $this->fursuit;
        });
    }
}
