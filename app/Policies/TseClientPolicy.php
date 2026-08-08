<?php

namespace App\Policies;

use App\Domain\Checkout\Models\TseClient;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Every ability is `is_admin`, exactly as it was. Only the docblocks are new.
 *
 * The manage panel asks `viewAny` and `view` and nothing else: TSE clients are read-only
 * there, since the local fabricator and the identity form are both gone (plan 2.10 #13 and
 * #14). No route in `routes/manage.php` reaches create, update or delete, and TseClientsTest
 * asserts that none exists, so the write abilities are unreachable from /admin whatever they
 * answer.
 *
 * They are deliberately left answering `is_admin` rather than being closed here. Filament
 * shared this policy and its create page and row EditAction were the only callers; both
 * went with the panel. Closing the abilities now would be a behaviour change on top of a
 * removal, so they keep their answer and stay unreachable by having no route.
 *
 * The real client lifecycle is `php artisan tse:update-state` and `tse:change-admin-pin`,
 * which talk to the TSE and do not go through this policy at all.
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
     * Determine whether the user can create TSE clients.
     * Only admins can create new TSE clients.
     *
     * Unreachable: nothing routes here. A client is issued by the TSS, and the Filament
     * page fabricated one from a random UUID Fiskaly never issued (plan 2.10 #13), which
     * is why the panel that replaced it offers no such screen.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the TSE client.
     * Only admins can update TSE clients.
     *
     * Unreachable: nothing routes here. `remote_id`, `serial_number` and `state` are the
     * signing identity past checkouts were signed under (plan 2.10 #14), which is why the
     * panel has no edit form.
     */
    public function update(User $user, TseClient $tseClient): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the TSE client.
     * Only admins can delete TSE clients.
     *
     * Nothing in either panel routes here: deleting a client would orphan every receipt
     * signed under its serial, which is the traceability KassenSichV requires.
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
