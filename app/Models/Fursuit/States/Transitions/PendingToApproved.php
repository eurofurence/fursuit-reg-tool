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
                ->log('Fursuit approved');
            $this->fursuit->user->notify(new FursuitApprovedNotification($this->fursuit));

            return $this->fursuit;
        });
    }
}
