<?php

namespace App\Models\Fursuit\States;

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
            ->allowTransition(Pending::class, Approved::class)
            ->allowTransition(Rejected::class, Pending::class)
            ->allowTransition(Pending::class, Rejected::class);
    }
}
