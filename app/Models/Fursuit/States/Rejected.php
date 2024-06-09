<?php

namespace App\Models\Fursuit\States;

use App\Models\Fursuit\States\FursuitStatusState;

class Rejected extends FursuitStatusState
{
    public static string $name = 'rejected';

    public function color(): string
    {
        // TODO: Implement color() method.
    }
}
