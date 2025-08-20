<?php

namespace App\Models\Fursuit\States;

class Approved extends FursuitStatusState
{
    public static string $name = 'approved';

    public function color(): string
    {
        return 'success';
    }
}
