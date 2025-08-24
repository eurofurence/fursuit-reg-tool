<?php

namespace App\Policies;

use App\Domain\Checkout\Models\TseClient;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TseClientPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any TSE clients.
     * Only admins can view TSE clients (sensitive security equipment).
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the TSE client.
     */
    public function view(User $user, TseClient $tseClient): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can create TSE clients.
     * Only admins can create new TSE clients.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the TSE client.
     * Only admins can update TSE clients.
     */
    public function update(User $user, TseClient $tseClient): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the TSE client.
     * Only admins can delete TSE clients.
     */
    public function delete(User $user, TseClient $tseClient): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the TSE client.
     */
    public function restore(User $user, TseClient $tseClient): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the TSE client.
     */
    public function forceDelete(User $user, TseClient $tseClient): bool
    {
        return $user->is_admin;
    }
}
