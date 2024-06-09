<?php

namespace App\Models\Badge\States;

use App\Models\Badge\States\BadgeStatusState;

class Printed extends BadgeStatusState
{
    public static string $name = 'printed';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
