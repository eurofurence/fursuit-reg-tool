<?php

namespace App\Enum;

enum PrintJobTypeEnum: string
{
    case Badge = 'badge';
    case Receipt = 'receipt';
}
