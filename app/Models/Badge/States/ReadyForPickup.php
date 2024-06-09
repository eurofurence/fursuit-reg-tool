<?php

namespace App\Models\Badge\States;

use App\Models\Badge\States\BadgeStatusState;

class ReadyForPickup extends BadgeStatusState
{
    public static string $name = 'ready_for_pickup';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
