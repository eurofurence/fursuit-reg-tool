<?php

namespace App\Models\Badge\States;

use App\Models\Badge\States\BadgeStatusState;

class PendingPayment extends BadgeStatusState
{
    public static string $name = 'unpaid';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
