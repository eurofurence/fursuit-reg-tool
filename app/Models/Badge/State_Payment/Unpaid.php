<?php

namespace App\Models\Badge\State_Payment;

class Unpaid extends BadgePaymentStatusState
{
  public static string $name = 'unpaid';

  public function color(): string
  {
    // TODO: Implement color() method.
  }
}
