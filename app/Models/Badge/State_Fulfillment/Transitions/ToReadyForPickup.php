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
            $this->badge->status = new ReadyForPickup($this->badge); // we will skip the printed state and go directly to ready for pickup
            $this->badge->paid_at = now();
            $this->badge->save();

            activity()
                ->performedOn($this->badge)
                ->log('Fursuit Badge Paid');

            // Send notification that badge is ready for pickup
            $user = $this->badge->fursuit->user;
            $event = $this->badge->fursuit->event;
            
            // Send notification if we're during the event or if the badge was just printed
            if ($event && ($event->isDuringEvent() || $this->badge->wasRecentlyCreated)) {
                $user->notify(new BadgePrintedNotification($this->badge));
            }

            return $this->badge;
        });
    }
}
