<?php

namespace App\Policies;

use App\Domain\Printing\Models\Printer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PrinterPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any printers.
     * Only admins can view printers.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the printer.
     */
    public function view(User $user, Printer $printer): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can create printers.
     * Only admins can create new printers.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the printer.
     * Only admins can update printers.
     */
    public function update(User $user, Printer $printer): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can delete the printer.
     * Only admins can delete printers.
     */
    public function delete(User $user, Printer $printer): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can restore the printer.
     */
    public function restore(User $user, Printer $printer): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the printer.
     */
    public function forceDelete(User $user, Printer $printer): bool
    {
        return $user->is_admin;
    }
}
