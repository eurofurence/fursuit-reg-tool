<?php

namespace App\Domain\CatchEmAll\Enums;

enum SpecialCodeType: int
{
    // Thank you for your service
    case STUFF = 1;
    // Fursuit-Badge-Employee
    case CATCH_EM_ALL_TEAM = 2;
    // The man, the myth, the legend
    case SPECIAL_STUFF = 3;
    // I Know A Guy
    case SECRET_CODE = 4;
    // Bug Bounty Hunter
    case BUG_BOUNTY = 5;
    // Location Explorer
    case EXPLORER = 6;
    // Fursuit Badge Team
    case FURSUIT_BADGE_TEAM = 7;
}
