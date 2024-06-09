<?php

namespace App\Models\Badge\States;

use App\Models\Badge\States\BadgeStatusState;

class Pending extends BadgeStatusState
{
    public static string $name = 'pending';
    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
