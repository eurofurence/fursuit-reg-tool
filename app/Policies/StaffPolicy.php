<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StaffPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any staff accounts.
     * Only admins can view the staff resource.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the staff account.
     */
    public function view(User $user, Staff $staff): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can create staff accounts.
     * Only admins can create new staff accounts.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the staff account.
     * Only admins can update staff accounts.
     */
    public function update(User $user, Staff $staff): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the staff account.
     * Only admins can delete staff accounts.
     */
    public function delete(User $user, Staff $staff): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the staff account.
     */
    public function restore(User $user, Staff $staff): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the staff account.
     */
    public function forceDelete(User $user, Staff $staff): bool
    {
        return $user->is_admin;
    }
}
