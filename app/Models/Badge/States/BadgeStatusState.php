<?php

namespace App\Models\Badge\States;

use App\Models\Badge\States\Transitions\PendingToPrintedTransition;
use Spatie\ModelStates\State;

abstract class BadgeStatusState extends State
{
    public static string $name;
    abstract public function color(): string;

    public static function config(): \Spatie\ModelStates\StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Printed::class, PendingToPrintedTransition::class)
            ->allowTransition(Printed::class, ReadyForPickup::class)
            ->allowTransition(PickedUp::class, ReadyForPickup::class) // Incase of pos user error we can revert the status
            ->allowTransition(ReadyForPickup::class, PickedUp::class);
    }
}
