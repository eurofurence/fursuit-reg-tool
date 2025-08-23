<?php

namespace App\Models\Badge\State_Fulfillment;

use App\Models\Badge\State_Fulfillment\Transitions\ToPrinted;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Spatie\ModelStates\State;

abstract class BadgeFulfillmentStatusState extends State implements HasColor, HasIcon
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
            ->default(Pending::class)
            ->allowTransition(Pending::class, Printed::class, ToPrinted::class)
            ->allowTransition(Printed::class, ReadyForPickup::class)
            ->allowTransition(PickedUp::class, ReadyForPickup::class) // Incase of pos user error we can revert the status
            ->allowTransition(ReadyForPickup::class, PickedUp::class);
    }
}
