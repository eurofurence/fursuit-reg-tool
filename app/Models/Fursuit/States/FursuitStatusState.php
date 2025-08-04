<?php

namespace App\Models\Fursuit\States;

use App\Models\Fursuit\States\Transitions\PendingToApproved;
use App\Models\Fursuit\States\Transitions\PendingToRejected;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class FursuitStatusState extends State
{
    public static string $name;

    abstract public function color(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Approved::class, PendingToApproved::class)
            ->allowTransition(Rejected::class, Pending::class)
            ->allowTransition(Pending::class, Rejected::class, PendingToRejected::class);
    }
}
