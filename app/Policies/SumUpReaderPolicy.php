<?php

namespace App\Policies;

use App\Models\SumUpReader;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SumUpReaderPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any SumUp readers.
     * Only admins can view SumUp readers.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the SumUp reader.
     */
    public function view(User $user, SumUpReader $sumUpReader): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can create SumUp readers.
     * Only admins can create new SumUp readers.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the SumUp reader.
     * Only admins can update SumUp readers.
     */
    public function update(User $user, SumUpReader $sumUpReader): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the SumUp reader.
     * Only admins can delete SumUp readers.
     */
    public function delete(User $user, SumUpReader $sumUpReader): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can read the pairing code back in the clear.
     *
     * Its own ability rather than a reuse of view/update: the pairing code is a payment
     * terminal credential and is never part of the list payload, so "may open the page"
     * and "may read the secret" have to be answerable separately even though both answer
     * is_admin today. Widening an existing ability to cover the reveal would change the
     * answer for every other caller of that ability.
     */
    public function reveal(User $user, SumUpReader $sumUpReader): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the SumUp reader.
     */
    public function restore(User $user, SumUpReader $sumUpReader): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the SumUp reader.
     */
    public function forceDelete(User $user, SumUpReader $sumUpReader): bool
    {
        return $user->is_admin;
    }
}
