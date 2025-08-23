<?php

namespace App\Models\Badge\State_Payment;

class Paid extends BadgePaymentStatusState
{
    public static string $name = 'paid';

    public function getColor(): string|array|null
    {
        return 'success';
    }

    public function getIcon(): ?string
    {
        return 'heroicon-m-check-circle';
    }
}
