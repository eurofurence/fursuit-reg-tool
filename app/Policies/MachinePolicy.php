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

    /**
     * Determine whether the user can mint a POS login link for the machine.
     *
     * Its own ability rather than a reuse of `update`: the link authenticates as the
     * till, so "may edit this record" and "may hand out a credential for it" have to be
     * answerable separately even though both say is_admin today (plan 2.10 #15).
     */
    public function loginLink(User $user, Machine $machine): bool
    {
        return $user->is_admin;
    }
}
