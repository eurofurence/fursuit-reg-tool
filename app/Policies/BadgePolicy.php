<?php

namespace App\Policies;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

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

        // Check if user has prepaid badges left
        $eventUser = $user->eventUser($event->id);
        if ($eventUser) {
            $prepaidBadges = $eventUser->prepaid_badges;
            $orderedBadges = $user->badges()
                ->whereHas('fursuit.event', function ($query) use ($event) {
                    $query->where('id', $event->id);
                })
                ->count();
            $prepaidBadgesLeft = max(0, $prepaidBadges - $orderedBadges);

            // Allow badge creation if user has prepaid badges left, regardless of order window
            if ($prepaidBadgesLeft > 0) {
                return true;
            }
        }

        // Allow badge creation only if event allows orders (for paid badges)
        if (! $event->allowsOrders()) {
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

        // Cannot edit when no active event
        $event = \App\Models\Event::getActiveEvent();
        if ($event === null) {
            return false;
        }

        // Cannot edit a badge that has already been printed
        if (!$badge->status_fulfillment->equals(Pending::class)) {
            return false;
        }

        // Users can edit their badges until printing, regardless of event order window or fursuit approval status
        return $user->id === $badge->fursuit->user_id;
    }

    public function delete(User $user, Badge $badge): bool
    {
        // Admin can do everything
        if ($user->is_admin && request()->routeIs('filament.*')) {
            return true;
        }

        // Cannot delete when no active event
        $event = \App\Models\Event::getActiveEvent();
        if ($event === null) {
            return false;
        }

        // Cannot delete a badge that has already been printed
        if (!$badge->status_fulfillment->equals(Pending::class)) {
            return false;
        }

        // Users can delete their badges until printing, regardless of event order window or fursuit approval status
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
