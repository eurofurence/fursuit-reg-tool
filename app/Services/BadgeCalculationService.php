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

        // All non-prepaid badges cost 3€ (300 cents)
        $baseFee = 300;

        return $baseFee;
    }
}
