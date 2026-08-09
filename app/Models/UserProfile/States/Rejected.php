<?php

namespace App\Models\UserProfile\States;

class Rejected extends UserProfileStatusState
{
    public static string $name = 'rejected';

    public function color(): string
    {
        return 'danger';
    }
}
