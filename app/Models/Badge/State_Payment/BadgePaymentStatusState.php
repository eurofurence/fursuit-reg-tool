<?php

namespace App\Models\Badge\State_Payment;

use App\Models\Badge\State_Payment\Transitions\ToPaid;
use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class BadgePaymentStatusState extends State
{
    public static string $name;

    abstract public function getColor(): string|array|null;

    abstract public function getIcon(): ?string;

    public function color(): string
    {
        return $this->getColor();
    }

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Unpaid::class)
            ->allowTransition(Unpaid::class, Paid::class, ToPaid::class);
    }
}
