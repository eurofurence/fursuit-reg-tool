<?php

namespace App\Services;

class BadgeCalculationService
{
    /**
     * @return int cents
     *
     * Returns the badge fee in cents
     */
    public static function calculate(
        bool $isSpareCopy = false,
        bool $isFreeBadge = false,
        bool $isLate = false
    ): int {
        if ($isSpareCopy) {
            return 200;
        }

        if ($isFreeBadge) {
            return 0;
        }

        $baseFee = 200;
        if ($isLate) {
            $baseFee += 200;
        }

        return $baseFee;
    }
}
