<?php

namespace App\Domain\Checkout\Models\Checkout\States;

use App\Models\Badge\States\BadgeStatusState;

class Active extends CheckoutStatusState
{
    public static string $name = 'ACTIVE';
    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
