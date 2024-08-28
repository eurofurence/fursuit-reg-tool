<?php

namespace App\Policies;

use App\Models\FCEA\UserCatchLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserCatchLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, UserCatchLog $user_catch_log): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UserCatchLog $user_catch_log): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, UserCatchLog $user_catch_log): bool
    {
        return false;
    }

    public function restore(User $user, UserCatchLog $user_catch_log): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, UserCatchLog $user_catch_log): bool
    {
        return $user->is_admin;
    }
}
