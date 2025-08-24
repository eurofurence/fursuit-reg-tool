<?php

namespace App\Models\Badge\State_Fulfillment;

class Processing extends BadgeFulfillmentStatusState
{
    public static string $name = 'processing';

    public function getColor(): string|array|null
    {
        return 'info';
    }

    public function getIcon(): ?string
    {
        return 'heroicon-m-printer';
    }
}
