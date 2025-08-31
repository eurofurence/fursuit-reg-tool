<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;


interface SpecialAchievement extends Achievement
{
    /**
     * Get the special code type for this achievement.
     * This determines when this achievement should be updated/triggered.
     *
     * @return SpecialCodeType
     */
    public function getSpecialCode(): SpecialCodeType;
}
