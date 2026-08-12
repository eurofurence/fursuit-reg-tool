<?php

namespace App\Models\UserProfile\States;

class Pending extends UserProfileStatusState
{
    public static string $name = 'pending';

    public function color(): string
    {
        return 'warning';
    }
}
