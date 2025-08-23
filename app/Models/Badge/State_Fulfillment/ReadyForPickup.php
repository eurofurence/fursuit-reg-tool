<?php

namespace App\Models\Badge\State_Fulfillment;

class ReadyForPickup extends BadgeFulfillmentStatusState
{
    public static string $name = 'ready_for_pickup';

    public function getColor(): string|array|null
    {
        return 'success';
    }

    public function getIcon(): ?string
    {
        return 'heroicon-m-check-circle';
    }
}
