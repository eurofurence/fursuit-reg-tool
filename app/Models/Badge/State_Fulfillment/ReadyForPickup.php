<?php

namespace App\Models\Badge\State_Fulfillment;

class ReadyForPickup extends BadgeFulfillmentStatusState
{
    public static string $name = 'ready_for_pickup';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
