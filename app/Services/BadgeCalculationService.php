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
            return 500;
        }

        if ($isFreeBadge) {
            return 0;
        }

        // All non-prepaid badges cost 5€ (500 cents)
        $baseFee = 500;

        return $baseFee;
    }
}
