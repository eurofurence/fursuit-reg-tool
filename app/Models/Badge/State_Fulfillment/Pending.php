<?php

namespace App\Models\Badge\State_Fulfillment;

class Pending extends BadgeFulfillmentStatusState
{
    public static string $name = 'pending';

    public function getColor(): string|array|null
    {
        return 'gray';
    }

    public function getIcon(): ?string
    {
        return 'heroicon-m-clock';
    }
}
