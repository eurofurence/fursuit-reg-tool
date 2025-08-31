<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

interface Achievement
{
    /**
     * Updates the progress of the achievement for the given user.
     *
     * IMPORTANT: This method should ONLY return the current progress value.
     * It should NOT grant achievements to the user or update his achievement records.
     * Achievement granting is handled by the AchievementService automatically.
     * Only override this behavior if you know what you are doing. (Hint use -1 as return for own handling)
     *
     * @param AchievementUpdateContext $context Contains user, catch data, and pre-calculated statistics
     * @return int The current progress value. If negative, the change should be ignored from outside.
     */
    public function updateAchievementProgress(AchievementUpdateContext $context): int;

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
