<?php

namespace App\Models\Fursuit\States\Transitions;

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Rejected;
use Spatie\ModelStates\Transition;

class PendingToRejected extends Transition
{
    public function __construct(public Fursuit $fursuit)
    {
    }

    public function handle()
    {
        $this->fursuit->status = Rejected::$name;
        $this->fursuit->rejected_at = now();
        $this->fursuit->approved_at = null;
        $this->fursuit->save();
    }
}
