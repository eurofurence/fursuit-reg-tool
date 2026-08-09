<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Every ability is `is_admin`, which is what the auto-discovered policy already returned
 *. Reviewers hold `access-manage` and therefore reach the panel, but not the
 * user list: this is the one module in the rebuild where the two gates diverge by design.
 *
 * `restore` and `forceDelete` describe operations that cannot happen - User carries no
 * SoftDeletes. the old panel was their only caller and is gone; they stay because deleting them
 * would flip the answer from false-by-policy to false-by-absence, which is a behaviour
 * change and not part of the removal.
 */
class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, User $model): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, User $model): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, User $model): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, User $model): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return $user->is_admin;
    }
}
