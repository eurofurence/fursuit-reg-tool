<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail, read-only.
 *
 * the old panel's ActivitiesRelationManager on the fursuit pages enabled create, edit, delete
 * and bulk delete on the log, so any panel user who could reach a
 * fursuit could fabricate or erase its history - and `causer` was not set on a manual
 * create, so a forged entry showed an empty `By`. An audit trail the audited party can
 * edit is not an audit trail. Every write ability is false here and no write route is
 * registered for it.
 *
 * The model lives in the Spatie package namespace, where policy auto-discovery looks for
 * Spatie\Activitylog\Policies\ActivityPolicy - a class that does not exist - so this is
 * registered explicitly in AuthServiceProvider or it never applies at all.
 */
class ActivityPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('access-manage');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('access-manage');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function restore(User $user, Activity $activity): bool
    {
        return false;
    }

    public function forceDelete(User $user, Activity $activity): bool
    {
        return false;
    }
}
