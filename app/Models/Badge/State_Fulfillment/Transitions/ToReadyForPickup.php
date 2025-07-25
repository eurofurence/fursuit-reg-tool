<?php

namespace App\Models\Badge\State_Fulfillment\Transitions;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToReadyForPickup extends Transition
{
  public function __construct(public Badge $badge)
  {
  }

  public function handle()
  {
    return DB::transaction(function () {
      $this->badge->status = new ReadyForPickup($this->badge); // we will skip the printed state and go directly to ready for pickup
      $this->badge->paid_at = now();
      $this->badge->save();

      activity()
        ->performedOn($this->badge)
        ->log('Fursuit Badge Paid');
      return $this->badge;
    });
  }
}
