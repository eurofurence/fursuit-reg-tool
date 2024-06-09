<?php

namespace App\Models\Badge\States;

use Spatie\ModelStates\State;

abstract class BadgeStatusState extends State
{
    public static string $name;
    abstract public function color(): string;

    public static function config(): \Spatie\ModelStates\StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Printed::class)
            ->allowTransition(Printed::class, ReadyForPickup::class)
            ->allowTransition(ReadyForPickup::class, PickedUp::class);
    }
}
