<?php

namespace App\Models\Fursuit\States\Transitions;

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\User;
use Spatie\ModelStates\Transition;

class PendingToApproved extends Transition
{
    public function __construct(public Fursuit $fursuit, public User $reviewer)
    {
    }

    public function handle()
    {
        $this->fursuit->status = Approved::$name;
        $this->fursuit->approved_at = now();
        $this->fursuit->rejected_at = null;
        $this->fursuit->save();
    }
}
