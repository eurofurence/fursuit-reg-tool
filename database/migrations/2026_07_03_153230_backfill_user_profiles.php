<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create a UserProfile for every existing user who doesn't already have one
     *
     * Not able to rollback (how can we tell the difference between existing profiles
     * vs. the ones created by this migration? and besides in prod this migration will
     * likely run alongside the other 2 so just rollback all 3 when in doubt :D)
     */
    public function up(): void
    {
        User::doesntHave('userProfile')->eachById(function (User $user) {
            $user->userProfile()->create();
        });
    }
};
