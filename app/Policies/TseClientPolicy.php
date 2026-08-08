<?php

namespace App\Policies;

use App\Domain\Checkout\Models\TseClient;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Every ability is `is_admin`, exactly as it was. Only the docblocks are new.
 *
 * The manage panel asks `viewAny`, `view`, `create` and `update`. `create` guards issuing a
 * client on the TSS and `update` guards registering and deregistering one - the whole
 * lifecycle, and all of it through Fiskaly. `delete` is never asked: a client's serial is
 * on every receipt it signed, so a row is retired, not removed.
 *
 * The identity itself is still not editable (plan 2.10 #14). `update` here means `state`
 * and nothing else; there is no PUT in the module for the two columns that matter.
 *
 * The TSS the clients live on is driven separately, by `php artisan tse:update-state` and
 * `tse:change-admin-pin`, which do not go through this policy at all.
 */
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
     * Determine whether the user can issue a TSE client on the security module.
     *
     * Asked by `TseClientController::store()`, which creates the row inside a transaction
     * with the observer's outbound PUT so a refusal upstream leaves nothing behind. That
     * is the part Filament's `createnew` skipped: it minted a random UUID and never called
     * anyone (plan 2.10 #13).
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can register or deregister the TSE client.
     *
     * `state` only. `remote_id` and `serial_number` are the signing identity past
     * checkouts were signed under (plan 2.10 #14), and no route writes them.
     */
    public function update(User $user, TseClient $tseClient): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the TSE client.
     * Only admins can delete TSE clients.
     *
     * Nothing routes here: deleting a client would orphan every receipt signed under its
     * serial, which is the traceability KassenSichV requires. Deregistration is what
     * retiring a client looks like.
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
