<?php

namespace App\Policies;

use App\Models\RfidTag;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * RFID tags, which are POS login credentials: a tag's `content` is the whole secret a
 * reader presents at the till (MachineUserAuthController resolves
 * RfidTag::active()->where('content', ...)).
 *
 * There is no policy at all today. RfidTagsRelationManager declares no canViewForRecord
 * and no can* overrides, so every ability on the model is open and the only thing
 * protecting it is that the relation manager lives on the Staff edit page, which
 * StaffPolicy keeps admin-only. One route registration outside that
 * page and the tags are readable and writable by anyone; the rebuild moves the tags onto
 * their own endpoints, so the guard has to stop being incidental.
 *
 * Every ability therefore answers `is_admin`, which is exactly what StaffPolicy answers,
 * so nobody who can reach the tags today loses them and nobody who cannot gains them.
 * Read is gated as tightly as write: a tag value that could be read back by an operator
 * who may not write it would be a credential leak with a shorter path than the login
 * screen.
 */
class RfidTagPolicy
{
    use HandlesAuthorization;

    /**
     * The nested table on the Staff edit page.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, RfidTag $rfidTag): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, RfidTag $rfidTag): bool
    {
        return $user->is_admin;
    }

    /**
     * Hard delete, row and bulk: `rfid_tags` carries no SoftDeletes.
     */
    public function delete(User $user, RfidTag $rfidTag): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, RfidTag $rfidTag): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, RfidTag $rfidTag): bool
    {
        return $user->is_admin;
    }
}
