<?php

namespace App\Models\Fursuit\States;

class Pending extends FursuitStatusState
{
    public static string $name = 'pending';

    public function color(): string
    {
        return 'warning';
    }
}
