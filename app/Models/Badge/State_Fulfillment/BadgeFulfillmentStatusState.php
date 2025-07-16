<?php

namespace App\Models\Badge\State_Fulfillment;

use App\Models\Badge\State_Fulfillment\Transitions\ToPrinted;
use Spatie\ModelStates\State;

abstract class BadgeFulfillmentStatusState extends State
{
  public static string $name;
  abstract public function color(): string;

  public static function config(): \Spatie\ModelStates\StateConfig
  {
    return parent::config()
      ->default(Pending::class)
      ->allowTransition(Pending::class, Printed::class, ToPrinted::class)
      ->allowTransition(Printed::class, ReadyForPickup::class)
      ->allowTransition(PickedUp::class, ReadyForPickup::class) // Incase of pos user error we can revert the status
      ->allowTransition(ReadyForPickup::class, PickedUp::class);
  }
}
