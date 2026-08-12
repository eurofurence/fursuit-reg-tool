<?php

namespace App\Models\UserProfile\States\Transitions;

use App\Models\User;
use App\Models\UserProfile\UserProfile;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class RejectedToApproved extends Transition
{
    public function __construct(public UserProfile $userProfile, public User $reviewer) {}

    public function handle()
    {
        return DB::transaction(function () {
            $this->userProfile->approved_at = now();
            $this->userProfile->rejected_at = null;
            $this->userProfile->rejection_reason = null;
            $this->userProfile->save();
            activity()
                ->performedOn($this->userProfile)
                ->causedBy($this->reviewer)
                ->log('User profile approved (was previously rejected)');

            return $this->userProfile;
        });
    }
}
