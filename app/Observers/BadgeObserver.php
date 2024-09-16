<?php

namespace App\Observers;

use App\Models\Badge\Badge;

class BadgeObserver
{
    public function updated(Badge $badge): void
    {
        // Based on tax_rate, calculate tax and update subtotal
        if ($badge->isDirty('total')) {
            $badge->subtotal = round($badge->total / 1.19,);
            $badge->tax = round($badge->total - $badge->subtotal);
            $badge->save();
        }
    }
}
