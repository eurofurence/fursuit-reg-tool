<?php

namespace App\Domain\Checkout\Models\Checkout\States;

class Active extends CheckoutStatusState
{
    public static string $name = 'ACTIVE';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
