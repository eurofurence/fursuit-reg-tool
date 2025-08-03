<?php

namespace App\Models\Badge\State_Fulfillment\Transitions;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Badge\State_Payment\Paid;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToPrinted extends Transition
{
    public function __construct(public Badge $badge) {}

    public function handle()
    {
        return DB::transaction(function () {
            // badge->custom id should contain attendee id - int id
            // int id should count up starting from one, the db field is unique so use while loop to find the next available id
            // Prevent race condition by locking the table via user id
            DB::transaction(function () {
                $nextId = 1;

                $user = $this->badge->fursuit->user()->lockForUpdate()->first();
                do {
                    $customId = "{$user->attendee_id}-".$nextId++;
                } while (Badge::where('custom_id', $customId)->exists());
                $this->badge->custom_id = $customId;

                if ($this->badge->status_payment->equals(Paid::class)) {
                    // we will skip the printed state and go directly to ready for pickup
                    $this->badge->status_fulfillment = new ReadyForPickup($this->badge);
                } else {
                    $this->badge->status_fulfillment = new Printed($this->badge);
                }

                $this->badge->printed_at = now();
                $this->badge->save();
            }, 5);

            activity()
                ->performedOn($this->badge)
                ->log('Fursuit Printed');

            return $this->badge;
        });
    }
}
