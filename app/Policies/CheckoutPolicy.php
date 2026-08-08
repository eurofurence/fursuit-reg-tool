<?php

namespace App\Policies;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Checkouts are fiscal records. This policy is the ceiling on what the panel may do to
 * one, and it is deliberately lower than "whatever the resource happened to allow".
 *
 * There is no policy at all today (audit landmine 51). Filament treats a model with no
 * policy as allowed, so the only thing standing between an is_reviewer-only account and a
 * checkout rewrite is CheckoutResource's own hard `canCreate/canEdit/canDelete => false` -
 * three lines in a UI class, on a record German KassenSichV requires to be tamper-evident.
 * Those three answers move here, where nothing that touches the model can route around
 * them (plan 2.2, plan 2.10 #19).
 *
 * Reading is `is_admin`. It was admin-or-reviewer at cutover, matching the unguarded
 * Filament resource, and that was wrong on its own terms: a checkout carries an
 * attendee's payment history, and a reviewer approves fursuit images. Reviewers were
 * narrowed to Dashboard, Badges and Fursuits, and this is one of the screens that went;
 * see docs/admin/roles.md.
 *
 * `printReceipt` is its own ability rather than a reuse of `update` or `view`. It is the
 * one write the audit documents - it queues a job against a printer and creates a print
 * job row - so it answers a different question from "may this operator read the record".
 *
 * Registered explicitly in AuthServiceProvider: the model lives under
 * App\Domain\Checkout\Models\Checkout, where Laravel's auto-discovery looks for
 * App\Domain\Checkout\Models\Policies\CheckoutPolicy, a directory that does not exist. An
 * unregistered policy here does not fail loudly; it falls open, which is how the gap got
 * here in the first place.
 */
class CheckoutPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    public function view(User $user, Checkout $checkout): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * Verbatim from CheckoutResource: `// Checkouts are created through POS only`.
     *
     * False for everyone, including an admin. There is no create route in
     * routes/manage/checkouts.php either, so this is the second of two locks rather than
     * the only one.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Verbatim from CheckoutResource: `// Checkouts should not be edited`.
     */
    public function update(User $user, Checkout $checkout): bool
    {
        return false;
    }

    /**
     * Verbatim from CheckoutResource: `// Checkouts should not be deleted`.
     */
    public function delete(User $user, Checkout $checkout): bool
    {
        return false;
    }

    /**
     * A checkout cannot be soft-deleted, so restoring and force-deleting are refused for
     * the same reason `delete` is: there is no path by which the panel unmakes a fiscal
     * record.
     */
    public function restore(User $user, Checkout $checkout): bool
    {
        return false;
    }

    public function forceDelete(User $user, Checkout $checkout): bool
    {
        return false;
    }

    /**
     * Queue the receipt for this checkout on the active receipt printer.
     *
     * The only write the panel is allowed to make against a checkout, and the audit is the
     * ceiling: it existed in Filament as the `print` row action and the `Print Receipt`
     * header action. It creates a print job, it does not change the checkout.
     */
    public function printReceipt(User $user, Checkout $checkout): bool
    {
        return $user->is_admin;
    }
}
