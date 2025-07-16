<?php

namespace App\Models\Badge\State_Payment;

class Paid extends BadgePaymentStatusState
{
  public static string $name = 'paid';

  public function color(): string
  {
    // TODO: Implement color() method.
  }
}
