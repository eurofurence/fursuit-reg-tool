<?php

namespace App\Domain\Checkout\Models\Checkout\States;

use App\Models\Badge\States\BadgeStatusState;

class Finished extends CheckoutStatusState
{
    public static string $name = 'FINISHED';
    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
