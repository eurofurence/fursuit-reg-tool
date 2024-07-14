<?php

namespace App\Policies;

use App\Models\Badge\Badge;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BadgePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Badge $badge): bool
    {
        if ($user->is_admin) {
            return true;
        }
        return $user->id === $badge->fursuit->user_id;
    }

    public function create(User $user): bool
    {
        // Do not allow ordering badges if there is no active event
        $event = \App\Models\Event::getActiveEvent();
        if ($event === null) {
            return false;
        }
        if($event->order_ends_at !== null) {
            if($event->order_ends_at->isPast()) {
                return false;
            }
        }
        return true;
    }

    public function update(User $user, Badge $badge): bool
    {
        $event = \App\Models\Event::getActiveEvent();
        // Copies cannot be edited
        if ($badge->extra_copy_of !== null) {
            return false;
        }
        // Admin can do everything
        if ($user->is_admin) {
            return true;
        }
        // Cannot edit when no active event
        if ($event === null) {
            return false;
        }
        // Cannot edit a badge that has already been printed
        if ($badge->printed_at !== null) {
            return false;
        }
        // Safety check
        if($event->order_ends_at !== null) {
            if($event->order_ends_at->isPast()) {
                return false;
            }
        }
        return $user->id === $badge->fursuit->user_id;
    }

    public function delete(User $user, Badge $badge): bool
    {
        $event = \App\Models\Event::getActiveEvent();
        // Admin can do everything
        if ($user->is_admin) {
            return true;
        }
        // Cannot edit when no active event
        if ($event === null) {
            return false;
        }
        // Cannot edit a badge that has already been printed
        if ($badge->printed_at !== null) {
            return false;
        }
        // Safety check
        if($event->order_ends_at !== null) {
            if($event->order_ends_at->isPast()) {
                return false;
            }
        }
        return $user->id === $badge->fursuit->user_id;
    }

    public function restore(User $user, Badge $badge): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, Badge $badge): bool
    {
        return $user->is_admin;
    }
}
