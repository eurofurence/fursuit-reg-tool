<?php

namespace App\Domain\Checkout\Models\Checkout\States;

class Finished extends CheckoutStatusState
{
  public static string $name = 'FINISHED';
  public function color(): string
  {
    // TODO: Implement color() method.
  }
}
