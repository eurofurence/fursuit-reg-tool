<?php

namespace App\Domain\Checkout\Enums;

enum TseClientStateEnum: string
{
    case REGISTERED = 'REGISTERED';
    case DEREGISTERED = 'DEREGISTERED';
}
