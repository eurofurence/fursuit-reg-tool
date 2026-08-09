<?php

namespace App\Models\UserProfile\States\Transitions;

use App\Models\UserProfile\UserProfile;
use Spatie\ModelStates\Transition;

class RejectedToPending extends Transition
{
    public function __construct(public UserProfile $userProfile) {}

    public function handle()
    {
        $this->userProfile->rejected_at = null;
        $this->userProfile->rejection_reason = null;
        $this->userProfile->save();

        return $this->userProfile;
    }
}
