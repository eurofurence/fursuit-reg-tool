<?php

namespace App\Models\Badge\States;

use App\Models\Badge\States\BadgeStatusState;

class PickedUp extends BadgeStatusState
{
    public static string $name = 'picked_up';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
