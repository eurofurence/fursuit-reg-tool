<?php

namespace App\Enum;

enum PrintJobStatusEnum: string
{
    case Pending = 'pending';
    case Printed = 'printed';
}
