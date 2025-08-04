<?php

namespace App\Domain\Checkout\Models\Checkout\States;

class Cancelled extends CheckoutStatusState
{
    public static string $name = 'CANCELLED';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
