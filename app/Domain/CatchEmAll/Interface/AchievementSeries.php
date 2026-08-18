<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Domain\CatchEmAll\Interface\Achievement;

interface AchievementSeries extends Achievement
{
    /**
     * Get the list of achievements that are part of this series.
     *
     * @return Achievement[]
     */
    public static function getAchievements(): array;
}
