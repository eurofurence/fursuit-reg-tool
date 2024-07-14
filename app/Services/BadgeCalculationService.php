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
        bool $doubleSided = false,
        bool $isSpareCopy = false,
        bool $isFreeBadge = false,
        bool $isLate = false
    ): int
    {
        if ($isSpareCopy) {
            return 200;
        }

        $baseFee = $isFreeBadge ? 0 : 200;
        if ($isLate) {
            $baseFee += 200;
        }
        if ($doubleSided) {
            $baseFee += 100;
        }
        return $baseFee;
    }
}
