<?php

namespace App\Models\Fursuit\States;

class Rejected extends FursuitStatusState
{
    public static string $name = 'rejected';

    public function color(): string
    {
        return 'danger';
    }
}
