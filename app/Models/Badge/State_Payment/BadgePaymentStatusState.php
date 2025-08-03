<?php

namespace App\Models\Badge\State_Payment;

use App\Models\Badge\State_Payment\Transitions\ToPaid;
use Spatie\ModelStates\State;

abstract class BadgePaymentStatusState extends State
{
    public static string $name;

    abstract public function color(): string;

    public static function config(): \Spatie\ModelStates\StateConfig
    {
        return parent::config()
            ->default(Unpaid::class)
            ->allowTransition(Unpaid::class, Paid::class, ToPaid::class);
    }
}
