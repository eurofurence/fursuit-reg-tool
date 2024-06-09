<?php

namespace App\Models\Fursuit\States\Transitions;

use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use Spatie\ModelStates\Transition;

class RejectedToPending extends Transition
{
    public function __construct(public Fursuit $fursuit)
    {
    }

    public function handle()
    {
        $this->fursuit->status = Pending::$name;
        $this->fursuit->save();
    }
}
