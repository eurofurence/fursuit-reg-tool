<?php

namespace App\Policies;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\Event;
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
        $event = Event::getActiveEvent();
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

    /**
     * "May this actor open the panel's badge editor for this record."
     *
     * This used to read `$user->is_admin && request()->routeIs('filament.*',
     * 'livewire.*')`, so the override only applied while the admin panel was Filament.
     * Moving the panel to /admin would have flipped every admin from "can edit any
     * badge" to "owner rules only" without anything saying so (audit landmine 52), and
     * /admin/badges/{badge}/edit would 403 on every badge an admin does not own.
     * Answered on the actor now, with no reference to the current request, so the same
     * question gets the same answer from a queue worker or a console command. See
     * rebuild-plan 2.2.
     *
     * Dropping the route check made this ability request-independent, which is the whole
     * point, but it also meant the *public* self-service editor at `PUT /badges/{badge}`
     * started honouring the operator override: a panel user editing somebody else's badge
     * through the attendee form walked past the extra-copy, print-lock, event-ended and
     * "still Pending" guards below, which is exactly what the print-lock comment says the
     * guard exists to prevent. Those guards are the rules of the attendee write path, not
     * of the panel one, so they live in `updateAsOwner()` and the public controller asks
     * that question instead.
     */
    public function update(User $user, Badge $badge): bool
    {
        /*
         * The panel override is `is_admin`, not `access-manage`. A reviewer still reads
         * the badge - `view` above is the ability the detail page authorizes - but moving
         * a badge between fulfillment or payment states is desk work rather than review
         * work, and this ability is what BadgeController::edit hands the page as
         * `canEdit`. See docs/admin/roles.md.
         */
        if ($user->is_admin) {
            return true;
        }

        return $this->updateAsOwner($user, $badge);
    }

    /**
     * The owner rules on their own, with no operator override.
     *
     * This is what the attendee-facing badge editor authorizes. Before the route check
     * came out of `update()` it was also what an admin got on those routes, since
     * `routeIs('filament.*')` is false on `/badges/*`, so this keeps that path answering
     * exactly as it did.
     */
    public function updateAsOwner(User $user, Badge $badge): bool
    {
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
        $event = Event::getActiveEvent();
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
        $event = Event::getActiveEvent();
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
