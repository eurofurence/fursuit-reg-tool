<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class UserObserver
{
    public function created(User $user): void
    {
        if (! Schema::hasTable('user_profiles')) {
            return;
        }

        $user->userProfile()->create();
    }
}
