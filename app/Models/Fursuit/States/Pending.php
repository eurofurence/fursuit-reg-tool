<?php

namespace App\Models\Fursuit\States;

use App\Models\Fursuit\States\FursuitStatusState;

class Pending extends FursuitStatusState
{
    public static string $name = 'pending';

    public function color(): string
    {
        return 'warning';
    }
}
