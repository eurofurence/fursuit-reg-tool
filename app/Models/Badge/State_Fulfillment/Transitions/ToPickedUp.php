<?php

namespace App\Models\Badge\State_Fulfillment\Transitions;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToPickedUp extends Transition
{
    public function __construct(public Badge $badge) {}

    public function handle()
    {
        return DB::transaction(function () {
            $this->badge->status_fulfillment = new PickedUp($this->badge);
            $this->badge->picked_up_at = now();

            // Every handout path (single, bulk, keyboard shortcut) runs this
            // transition. Null when nobody is logged in on the machine-user
            // guard: console commands and admin-panel handouts have no staff
            // member behind them.
            $this->badge->picked_up_by_staff_id = auth('machine-user')->id();

            // Set paid_at if not already set (for badges that skip ready_for_pickup)
            if (! $this->badge->paid_at) {
                $this->badge->paid_at = now();
            }

            $this->badge->save();

            activity()
                ->performedOn($this->badge)
                ->log('Badge Handed Out');

            return $this->badge;
        });
    }
}
