<?php

namespace App\Policies;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Fursuits (audit 3, plan 2.2).
 *
 * Unchanged from the Filament panel on purpose. Two points are load-bearing for the
 * /admin rebuild and are written down here rather than rediscovered:
 *
 *  - `create()` stays false. It is not a bug to fix during a rewrite (audit landmine
 *    38): fursuits come from the attendee ordering flow, never from staff. No create
 *    route is registered, so the false is the only guard that has to hold.
 *  - Reading is `is_admin || is_reviewer` while writing the row is `is_admin`. The whole
 *    moderation workflow - claim, approve, reject, notify - is gated on `view`, not
 *    `update`, because a reviewer is exactly the role that works the queue and would
 *    otherwise be locked out of the only thing it exists to do. That mirrors the
 *    Filament page, whose header actions carried no authorization beyond the resource's
 *    view check.
 */
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
