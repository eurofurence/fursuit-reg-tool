<?php

namespace App\Enum;

enum EventStateEnum: string
{
    case CLOSED = 'closed';
    case COUNTDOWN = 'countdown';
    case PREORDER = 'preorder';
    case LATE = 'late';

}
