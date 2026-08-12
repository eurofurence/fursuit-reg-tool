<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserProfile\UserProfile;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserProfilePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->is_admin || $user->is_reviewer;
    }

    public function view(User $user, UserProfile $userProfile): bool
    {
        return $user->is_admin || $user->is_reviewer;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UserProfile $userProfile): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, UserProfile $userProfile): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, UserProfile $userProfile): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, UserProfile $userProfile): bool
    {
        return $user->is_admin;
    }
}
