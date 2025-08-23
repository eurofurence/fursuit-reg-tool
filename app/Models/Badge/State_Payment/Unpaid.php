<?php

namespace App\Models\Badge\State_Payment;

class Unpaid extends BadgePaymentStatusState
{
    public static string $name = 'unpaid';

    public function getColor(): string|array|null
    {
        return 'danger';
    }

    public function getIcon(): ?string
    {
        return 'heroicon-m-x-circle';
    }
}
