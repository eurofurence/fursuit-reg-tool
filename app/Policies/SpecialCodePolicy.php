<?php

namespace App\Policies;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Special codes are admin-only.
 *
 * There is no policy at all today, and the old panel treats a model with no policy as
 * allowed, so an is_reviewer-only account can create, edit and delete Catch-Em-All
 * codes that award points while needing is_admin merely to look at a printer
 *. Every ability is is_admin.
 *
 * Registered explicitly in AuthServiceProvider: the model lives under App\Domain\**,
 * where Laravel's policy auto-discovery looks in a directory that does not exist.
 */
class SpecialCodePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, SpecialCode $specialCode): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, SpecialCode $specialCode): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, SpecialCode $specialCode): bool
    {
        return $user->is_admin;
    }
}
