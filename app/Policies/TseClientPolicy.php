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
 * They are deliberately left answering `is_admin` rather than being closed here, because
 * this policy is shared: Filament consults it too, and /admin-legacy keeps its create page
 * and its row EditAction until cutover. Closing the ability would take two screens the
 * parity contract documents as working off a panel that is supposed to keep running (the
 * plan's own rule for phase 10). The two screens are classified as defects and the panel
 * that replaces them does not carry them; retiring them is the cutover's job, not this
 * module's.
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
     * Reached from /admin-legacy only. A client is issued by the TSS, and the Filament
     * page fabricates one from a random UUID Fiskaly never issued (plan 2.10 #13), which
     * is why the new panel offers no such screen.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the TSE client.
     * Only admins can update TSE clients.
     *
     * Reached from /admin-legacy only. `remote_id`, `serial_number` and `state` are the
     * signing identity past checkouts were signed under (plan 2.10 #14), which is why the
     * new panel has no edit form.
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
