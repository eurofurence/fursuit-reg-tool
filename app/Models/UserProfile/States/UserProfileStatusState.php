<?php

namespace App\Models\UserProfile\States;

use App\Models\UserProfile\States\Transitions\PendingToApproved;
use App\Models\UserProfile\States\Transitions\PendingToRejected;
use App\Models\UserProfile\States\Transitions\RejectedToApproved;
use App\Models\UserProfile\States\Transitions\RejectedToPending;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class UserProfileStatusState extends State
{
    public static string $name;

    abstract public function color(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Approved::class, PendingToApproved::class)
            ->allowTransition(Pending::class, Rejected::class, PendingToRejected::class)
            ->allowTransition(Rejected::class, Approved::class, RejectedToApproved::class)
            ->allowTransition(Rejected::class, Pending::class, RejectedToPending::class);
    }
}
