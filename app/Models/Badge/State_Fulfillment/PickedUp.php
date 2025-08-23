<?php

namespace App\Models\Badge\State_Fulfillment;

class PickedUp extends BadgeFulfillmentStatusState
{
    public static string $name = 'picked_up';

    public function getColor(): string|array|null
    {
        return 'warning';
    }

    public function getIcon(): ?string
    {
        return 'heroicon-m-hand-raised';
    }
}
