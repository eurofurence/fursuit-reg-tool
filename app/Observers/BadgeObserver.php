<?php

namespace App\Observers;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use Illuminate\Routing\Route;

class BadgeObserver
{
    public function updated(Badge $badge): void
    {
        // Based on tax_rate, calculate tax and update subtotal
        if ($badge->isDirty('total')) {
            $badge->subtotal = round($badge->total / 1.19,);
            $badge->tax = round($badge->total - $badge->subtotal);

            $user = $badge->fursuit->user;
            $originalTotal = $badge->getOriginal();
            $newTotal = $badge->total;
            $badge->total = $originalTotal;
            $user->refund($badge);
            $badge->total = $newTotal;
            $user->forcePay($badge);

            $badge->saveQuietly();
        }
    }
}
