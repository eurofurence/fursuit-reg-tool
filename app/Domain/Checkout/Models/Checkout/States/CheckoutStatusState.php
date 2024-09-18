<?php

namespace App\Domain\Checkout\Models\Checkout\States;

use App\Domain\Checkout\Models\Checkout\Transitions\ToCancelled;
use App\Domain\Checkout\Models\Checkout\Transitions\ToFinished;
use Spatie\ModelStates\State;

abstract class CheckoutStatusState extends State
{
    public static string $name;
    abstract public function color(): string;

    public static function config(): \Spatie\ModelStates\StateConfig
    {
        return parent::config()
            ->default(Active::class)
            ->allowTransition(Active::class, Cancelled::class, ToCancelled::class)
            ->allowTransition(Active::class, Finished::class, ToFinished::class);
    }
}
