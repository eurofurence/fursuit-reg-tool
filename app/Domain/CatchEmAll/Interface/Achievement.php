<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\User;

interface Achievement
{
    /**
     * Updates the progress of the achievement for the given user.
     *
     * @param User $user The user to update the achievement for
     * @param UserCatch $newCatch The new catch to update the achievement progress with
     * @return void
     */
    public function updateAchievementProgress(User $user, UserCatch $newCatch): bool;

    /**
     * Get the unique identifier for the achievement.
     * @return string
     */
    public function getId(): string;

    /**
     * Get the display title for the achievement.
     * @return string
     */
    public function getTile(): string;

    /**
     * Get the description of the achievement.
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get the icon representing the achievement.
     * @return string
     */
    public function getIcon(): string;

    /**
     * Get the max progress towards the achievement.
     * @return int
     */
    public function getMaxProgress(): int;

    /**
     * Check if achievement is secret (does not get displayed while not achieved).
     * @return bool
     */
    public function isSecret(): bool;

    /**
     * Check if achievement is optional (does not get counted towards overall achievement progress).
     * @return bool
     */
    public function isOptional(): bool;

    /**
     * Check if achievement is hidden (does not get displayed at all).
     * @return bool
     */
    public function isHidden(): bool;
}
