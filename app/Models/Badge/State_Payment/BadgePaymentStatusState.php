<?php

namespace App\Models\Badge\State_Payment;

use App\Models\Badge\State_Payment\Transitions\ToPaid;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Spatie\ModelStates\State;

abstract class BadgePaymentStatusState extends State implements HasColor, HasIcon
{
    public static string $name;

    abstract public function getColor(): string|array|null;

    abstract public function getIcon(): ?string;

    public function color(): string
    {
        return $this->getColor();
    }

    public static function config(): \Spatie\ModelStates\StateConfig
    {
        return parent::config()
            ->default(Unpaid::class)
            ->allowTransition(Unpaid::class, Paid::class, ToPaid::class);
    }
}
