<?php

namespace App\Policies;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FursuitPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->is_admin || $user->is_reviewer;
    }

    public function view(User $user, Fursuit $fursuit): bool
    {
        return $user->is_admin || $user->is_reviewer;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Fursuit $fursuit): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, Fursuit $fursuit): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, Fursuit $fursuit): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, Fursuit $fursuit): bool
    {
        return $user->is_admin;
    }
}
