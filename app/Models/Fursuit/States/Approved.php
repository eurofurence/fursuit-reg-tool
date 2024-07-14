<?php

namespace App\Models\Fursuit\States;

use App\Models\Fursuit\States\FursuitStatusState;

class Approved extends FursuitStatusState
{
    public static string $name = 'approved';

    public function color(): string
    {
        return 'success';
    }
}
