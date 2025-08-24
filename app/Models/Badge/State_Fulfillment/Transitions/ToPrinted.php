<?php

namespace App\Models\Badge\State_Fulfillment\Transitions;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Badge\State_Payment\Paid;
use App\Notifications\BadgePrintedNotification;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\ModelStates\Transition;

class ToPrinted extends Transition
{
    public function __construct(public Badge $badge) {}

    public function handle()
    {
        return DB::transaction(function () {
            $user = $this->badge->fursuit->user;
            $event = $this->badge->fursuit->event;
            $eventUser = $user->eventUser($event->id);

            if (! $eventUser) {
                throw new RuntimeException("EventUser not found for user $user->id in event $event->id");
            }

            // Lock the user record to prevent concurrent badge processing for the same user
            $user->lockForUpdate();

            // Lock all badges for this user in this event to prevent race conditions
            // This ensures no other process can modify badges or assign custom_ids
            // for this user while we're determining the next available number
            $userBadgesInEvent = Badge::whereHas('fursuit', static function ($query) use ($user, $event) {
                $query->where('user_id', $user->id)
                    ->where('event_id', $event->id);
            })->lockForUpdate()->get();

            // Find the highest existing badge number for this user in this event
            $maxBadgeNumber = 0;
            $attendeeIdPrefix = $eventUser->attendee_id.'-';

            foreach ($userBadgesInEvent as $badge) {
                if ($badge->custom_id && str_starts_with($badge->custom_id, $attendeeIdPrefix)) {
                    $badgeNumber = (int) str_replace($attendeeIdPrefix, '', $badge->custom_id);
                    $maxBadgeNumber = max($maxBadgeNumber, $badgeNumber);
                }
            }

            // Assign the next sequential number
            $nextId = $maxBadgeNumber + 1;
            $customId = $eventUser->attendee_id.'-'.$nextId;

            // Assign the custom_id and update badge status
            $this->badge->custom_id = $customId;

            if ($this->badge->status_payment->equals(Paid::class)) {
                // we will skip the printed state and go directly to ready for pickup
                $this->badge->status_fulfillment = new ReadyForPickup($this->badge);
            } else {
                $this->badge->status_fulfillment = new Printed($this->badge);
            }

            $this->badge->printed_at = now();
            $this->badge->save();

            activity()
                ->performedOn($this->badge)
                ->log('Fursuit Printed');

            // Send notification only during the event (not for mass printing before con)
            if ($event && $event->isDuringEvent()) {
                $user->notify(new BadgePrintedNotification($this->badge));
            }

            return $this->badge;
        });
    }
}
