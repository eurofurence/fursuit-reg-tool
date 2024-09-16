<?php

namespace App\Domain\Checkout\Models\Checkout\States;

use App\Models\Badge\States\BadgeStatusState;

class Cancelled extends CheckoutStatusState
{
    public static string $name = 'CANCELLED';
    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
