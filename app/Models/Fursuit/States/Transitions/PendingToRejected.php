<?php

namespace App\Models\Fursuit\States\Transitions;

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Rejected;
use App\Models\User;
use App\Notifications\FursuitRejectedNotification;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class PendingToRejected extends Transition
{
    public function __construct(public Fursuit $fursuit, public User $reviewer, public string $reason)
    {
    }

    public function handle()
    {
        return DB::transaction(function () {
            $this->fursuit->status = new Rejected($this->fursuit);
            $this->fursuit->rejected_at = now();
            $this->fursuit->approved_at = null;
            $this->fursuit->save();
            activity()
                ->performedOn($this->fursuit)
                ->by($this->reviewer)
                ->withProperties(['reason' => $this->reason])
                ->log('Fursuit rejected');
            $this->fursuit->user->notify(new FursuitRejectedNotification($this->fursuit, $this->reason));
            return $this->fursuit;
        });
    }
}
