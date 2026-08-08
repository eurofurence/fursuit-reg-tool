<?php

namespace App\Models\Badge\State_Fulfillment\Transitions;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Notifications\BadgePrintedNotification;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToReadyForPickup extends Transition
{
    public function __construct(public Badge $badge) {}

    public function handle()
    {
        return DB::transaction(function () {
            $this->badge->status_fulfillment = new ReadyForPickup($this->badge); // we will skip the printed state and go directly to ready for pickup
            $this->badge->paid_at = now();

            // Also the undo path for a mis-scanned handout: the badge is back in
            // the queue, so it counts towards nobody's tally.
            $this->badge->picked_up_at = null;
            $this->badge->picked_up_by_staff_id = null;

            $this->badge->save();

            activity()
                ->performedOn($this->badge)
                ->log('Fursuit Badge Paid');

            // Send notification that badge is ready for pickup
            $user = $this->badge->fursuit->user;
            $event = $this->badge->fursuit->event;

            // Send notification only during the event
            // This matches the behavior in ToPrinted transition for consistency
            if ($event && $event->isDuringEvent()) {
                $user->notify(new BadgePrintedNotification($this->badge));
            }

            return $this->badge;
        });
    }
}
