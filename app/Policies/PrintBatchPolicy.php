<?php

namespace App\Policies;

use App\Domain\Printing\Models\PrintBatch;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Gate;

/**
 * New. `PrintBatchResource` has no policy at all, so pausing, resuming and cancelling a
 * live convention print run are reachable today by anyone who passes the panel gate,
 * including an `is_reviewer`-only user - while merely looking at a printer needs
 * `is_admin` through `PrinterPolicy` (audit 51). Plan 2.10 #18 closes that.
 *
 * Reading stays open to every `access-manage` holder, because batch oversight is what the
 * resource exists for: staff who are not standing at the printer watch the run from here.
 * The four things that change a run - pause, resume, cancel and the manual verification -
 * require `is_admin`.
 *
 * `verify` acts on a PrintJob but is asked of the batch, which is what Filament's relation
 * manager did: a relation manager authorizes against the owner record, not against the
 * child. Keeping the same subject means "may this operator touch this run" has one answer
 * rather than two that can drift, and `PrintJobPolicy` is left to govern the print-job
 * module on its own.
 *
 * `create`, `update` and `delete` are false and stay false. A batch can only come from
 * `PrintBatch::build()`, which needs the badges it will contain, and it is immutable once
 * built: `canCreate(): false` is the only resource-level override the Filament resource
 * carries, and there is no delete action on batches anywhere (audit 4.8, audit 7.7).
 */
class PrintBatchPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->reads($user);
    }

    public function view(User $user, PrintBatch $printBatch): bool
    {
        return $this->reads($user);
    }

    /**
     * `canCreate(): false`. Batches come from the badge list's print action, which is the
     * only path that can freeze the sequence and lock the badges together.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * A batch is immutable once built, so there is nothing to edit. The run controls are
     * their own abilities below rather than a reuse of this one.
     */
    public function update(User $user, PrintBatch $printBatch): bool
    {
        return false;
    }

    /**
     * No delete action exists on batches, and deleting one would orphan its jobs and the
     * printing locks they hold. Cancelling is the operation that stops a run.
     */
    public function delete(User $user, PrintBatch $printBatch): bool
    {
        return false;
    }

    public function pause(User $user, PrintBatch $printBatch): bool
    {
        return (bool) $user->is_admin;
    }

    public function resume(User $user, PrintBatch $printBatch): bool
    {
        return (bool) $user->is_admin;
    }

    public function cancel(User $user, PrintBatch $printBatch): bool
    {
        return (bool) $user->is_admin;
    }

    public function verify(User $user, PrintBatch $printBatch): bool
    {
        return (bool) $user->is_admin;
    }

    /**
     * The panel gate itself, asked through Gate rather than retyped, so "who may read the
     * panel" keeps one definition in AuthServiceProvider.
     */
    private function reads(User $user): bool
    {
        return Gate::forUser($user)->allows('access-manage');
    }
}
