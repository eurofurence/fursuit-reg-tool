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
        return $user->is_admin || $user->is_reviewer;
    }

    public function view(User $user, Badge $badge): bool
    {
        if (($user->is_admin || $user->is_reviewer)) {
            return true;
        }

        return $user->id === $badge->fursuit->user_id;
    }

    public function create(User $user): bool
    {
        // Admin can override
        if ($user->is_admin) {
            return true;
        }

        // Do not allow ordering badges if there is no active event
        $event = \App\Models\Event::getActiveEvent();
        if ($event === null) {
            return false;
        }

        // Check if user has prepaid badges left FIRST - these bypass order window restrictions.
        // NOTE: this is the raw prepaid allowance (prepaid_badges - already ordered) and is
        // intentionally NOT the same as User::getPrepaidBadgesLeft(), which additionally
        // subtracts the now-unhonored free badge. This decides whether the user may create a
        // badge *at all* (it may end up being a paid badge); getPrepaidBadgesLeft() only
        // decides whether that badge is free. See PrepaidBadgePriceConsistencyTest.
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

        // For paid badges, check if event allows orders
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

        // Committed to a print batch. The artwork was rendered when the batch
        // was built, so an edit now would produce a card that no longer matches
        // the order, and nobody would spot it until pickup.
        if ($badge->isPrintingLocked()) {
            return false;
        }

        // Cannot edit when there is no active event, or once the event itself has ended.
        //
        // NOTE: this deliberately checks the *event end* (ends_at), NOT the order window
        // (allowsOrders()/order_ends_at). Editing a not-yet-printed badge must stay possible
        // after ordering closes but while the event is still running — otherwise owners of
        // already-ordered (paid, non-free) badges lose their edit button during the valid
        // pre-printing period, while free-badge owners keep it. Gating on the order window was
        // the cause of that "some users can't edit" bug. Printed badges are blocked below; this
        // mirrors the delete() policy and the "regardless of event order window" comment there.
        $event = \App\Models\Event::getActiveEvent();
        if ($event === null || $event->ends_at < now()) {
            return false;
        }

        // Cannot edit a badge that has already been printed
        if (! $badge->status_fulfillment->equals(Pending::class)) {
            return false;
        }

        // Users can edit their badges until printing, regardless of event order window or fursuit approval status
        return $user->id === $badge->fursuit->user_id;
    }

    public function delete(User $user, Badge $badge): bool
    {
        // Admin can do everything
        if ($user->is_admin) {
            return true;
        }

        // Cannot delete when no active event
        $event = \App\Models\Event::getActiveEvent();
        if ($event === null) {
            return false;
        }

        // Cannot delete a badge that has already been printed
        if (! $badge->status_fulfillment->equals(Pending::class)) {
            return false;
        }

        // Deleting a badge that is queued in a batch would leave the print run
        // pointing at something that no longer exists.
        if (! $user->is_admin && $badge->isPrintingLocked()) {
            return false;
        }

        // Users can delete their badges until printing, regardless of event order window or fursuit approval status
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
