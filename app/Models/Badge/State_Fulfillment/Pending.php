<?php

namespace App\Models\Badge\State_Fulfillment;

class Pending extends BadgeFulfillmentStatusState
{
    public static string $name = 'pending';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
