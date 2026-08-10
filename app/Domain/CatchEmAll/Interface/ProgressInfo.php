<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Models\EventUser;

interface ProgressInfo extends Expandable
{
    /**
     * Get a list of all reached steps from a specific user for this achievement.
     *
     * @return array<string> An array of current progress information for the given user.
     */
    public function getCurrentProgress(EventUser $eventUser): array;

    /**
     * Get a list of all steps to reach the goal
     *
     * @return array<string> An array of all steps to reach the goal
     */
    public function getTotalProgress(): array;
}
