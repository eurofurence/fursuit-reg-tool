<?php

namespace App\Models\Badge\State_Fulfillment;

class Printed extends BadgeFulfillmentStatusState
{
    public static string $name = 'printed';

    public function getColor(): string|array|null
    {
        return 'info';
    }

    public function getIcon(): ?string
    {
        return 'heroicon-m-printer';
    }
}
