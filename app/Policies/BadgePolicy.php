<?php

namespace App\Policies;

use App\Enum\EventStateEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\States\Pending;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Routing\Route;

class BadgePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->is_admin && request()->routeIs('filament.*');
    }

    public function view(User $user, Badge $badge): bool
    {
        if ($user->is_admin && request()->routeIs('filament.*')) {
            return true;
        }
        return $user->id === $badge->fursuit->user_id;
    }

    public function create(User $user): bool
    {
        // Admin can override
        if ($user->is_admin && request()->routeIs('filament.*')) {
            return true;
        }

        // Do not allow ordering badges if there is no active event
        $event = \App\Models\Event::getActiveEvent();
        if ($event === null) {
            return false;
        }

        // Safety check if in CLOSED OR LATE return false
        if ($event->state === \App\Enum\EventStateEnum::CLOSED || $event->state === \App\Enum\EventStateEnum::COUNTDOWN) {
            return false;
        }

        return true;
    }

    public function update(User $user, Badge $badge): bool
    {
        // Admin can override
        if ($user->is_admin && request()->routeIs('filament.*', 'livewire.*')) {
            return true;
        }

        // Copies cannot be edited
        if ($badge->extra_copy_of !== null) {
            return false;
        }

        $event = \App\Models\Event::getActiveEvent();
        return $this->delete($user, $badge);
    }

    public function delete(User $user, Badge $badge): bool
    {
        $event = \App\Models\Event::getActiveEvent();
        // Admin can do everything IN FILAMENT

        // Admin can do everything
        if ($user->is_admin && request()->routeIs('filament.*')) {
            return true;
        }
        // Cannot edit when no active event
        if ($event === null) {
            return false;
        }
        // Cannot edit a badge that has already been printed
        if ((string) $badge->status !== Pending::$name) {
            return false;
        }

        // Safety check if in CLOSED OR LATE return false
        if ($event->state === \App\Enum\EventStateEnum::CLOSED || $event->state === \App\Enum\EventStateEnum::COUNTDOWN) {
            return false;
        }

        return $user->id === $badge->fursuit->user_id;
    }

    public function restore(User $user, Badge $badge): bool
    {
        return $user->is_admin && request()->routeIs('filament.*');
    }

    public function forceDelete(User $user, Badge $badge): bool
    {
        return $user->is_admin && request()->routeIs('filament.*');
    }
}
