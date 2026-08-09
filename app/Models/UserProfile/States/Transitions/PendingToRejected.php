<?php

namespace App\Models\UserProfile\States\Transitions;

use App\Models\User;
use App\Models\UserProfile\UserProfile;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class PendingToRejected extends Transition
{
    public function __construct(public UserProfile $userProfile, public User $reviewer, public string $reason) {}

    public function handle()
    {
        return DB::transaction(function () {
            $this->userProfile->rejected_at = now();
            $this->userProfile->approved_at = null;
            $this->userProfile->rejection_reason = $this->reason;
            $this->userProfile->save();
            activity()
                ->performedOn($this->userProfile)
                ->causedBy($this->reviewer)
                ->withProperties(['reason' => $this->reason])
                ->log('User profile rejected');

            return $this->userProfile;
        });
    }
}
