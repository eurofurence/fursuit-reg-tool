<?php

namespace App\Models\Badge\State_Fulfillment\Transitions;

use App\Models\Badge\Badge;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\ModelStates\Transition;

class ToProcessing extends Transition
{
    public function __construct(
        private Badge $badge
    ) {
    }

    public function handle(): Badge
    {
        \Log::info('ToProcessing transition starting', [
            'badge_id' => $this->badge->id,
            'current_fulfillment' => $this->badge->status_fulfillment->getValue(),
            'current_payment' => $this->badge->status_payment->getValue(),
        ]);
        
        return DB::transaction(function () {
            $user = $this->badge->fursuit->user;
            $event = $this->badge->fursuit->event;
            $eventUser = $user->eventUser($event->id);

            if (! $eventUser) {
                throw new RuntimeException("EventUser not found for user $user->id in event $event->id");
            }

            // Only generate custom_id if it doesn't already exist
            if (! $this->badge->custom_id) {
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

                // Assign the custom_id
                $this->badge->custom_id = $customId;
            }
            
            // IMPORTANT: Actually set the state to Processing
            $this->badge->status_fulfillment = new \App\Models\Badge\State_Fulfillment\Processing($this->badge);
            $this->badge->save();

            // Log activity when badge transitions to processing
            activity()
                ->performedOn($this->badge)
                ->causedBy(auth()->user())
                ->withProperties([
                    'old_status' => 'pending',
                    'new_status' => 'processing',
                    'custom_id' => $this->badge->custom_id,
                ])
                ->log('Badge sent for printing');

            \Log::info('ToProcessing transition completed', [
                'badge_id' => $this->badge->id,
                'custom_id' => $this->badge->custom_id,
                'final_fulfillment' => $this->badge->status_fulfillment->getValue(),
                'final_payment' => $this->badge->status_payment->getValue(),
            ]);

            return $this->badge;
        });
    }
}