<?php

namespace App\Models\UserProfile\States;

class Approved extends UserProfileStatusState
{
    public static string $name = 'approved';

    public function color(): string
    {
        return 'success';
    }
}
