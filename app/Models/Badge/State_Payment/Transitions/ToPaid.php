<?php

namespace App\Models\Badge\State_Payment\Transitions;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Badge\State_Payment\Paid;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

class ToPaid extends Transition
{
  public function __construct(public Badge $badge)
  {
  }

  public function handle()
  {
    return DB::transaction(function () {
      if ($this->badge->status_fulfillment->equals(Printed::class)) {
        $this->badge->status_fulfillment = new ReadyForPickup($this->badge);
      }

      $this->badge->status_payment = new Paid($this->badge);

      $this->badge->paid_at = now();
      $this->badge->save();

      activity()
        ->performedOn($this->badge)
        ->log('Fursuit Badge Paid');
      return $this->badge;
    });
  }
}
