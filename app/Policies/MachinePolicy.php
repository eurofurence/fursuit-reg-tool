<?php

namespace App\Policies;

use App\Models\Machine;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MachinePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any machines.
     * Only admins can view machines.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the machine.
     */
    public function view(User $user, Machine $machine): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can create machines.
     * Only admins can create new machines.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the machine.
     * Only admins can update machines.
     */
    public function update(User $user, Machine $machine): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the machine.
     * Only admins can delete machines.
     */
    public function delete(User $user, Machine $machine): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the machine.
     */
    public function restore(User $user, Machine $machine): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the machine.
     */
    public function forceDelete(User $user, Machine $machine): bool
    {
        return $user->is_admin;
    }
}
