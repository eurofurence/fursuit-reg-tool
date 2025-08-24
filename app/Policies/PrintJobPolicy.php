<?php

namespace App\Policies;

use App\Domain\Printing\Models\PrintJob;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PrintJobPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any print jobs.
     * Only admins can view print jobs.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the print job.
     */
    public function view(User $user, PrintJob $printJob): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can create print jobs.
     * Only admins can create print jobs.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the print job.
     * Only admins can update print jobs.
     */
    public function update(User $user, PrintJob $printJob): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the print job.
     * Only admins can delete print jobs.
     */
    public function delete(User $user, PrintJob $printJob): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the print job.
     */
    public function restore(User $user, PrintJob $printJob): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the print job.
     */
    public function forceDelete(User $user, PrintJob $printJob): bool
    {
        return $user->is_admin;
    }
}
