<?php

namespace App\Domain\CatchEmAll\Achievements\Utils;

use App\Domain\CatchEmAll\Achievements\BugBountyHunter;
use App\Domain\CatchEmAll\Interface\Achievement;

class AchievementRegister
{
    /**
     * Registry of all available achievement classes.
     * Add new achievement classes here to register them.
     *
     * @var array<class-string<Achievement>, Achievement>
     */
    private static array $achievements = [
        BugBountyHunter::class => new BugBountyHunter(),
        // Add new achievements here in the format:
        // AchievementClassName::class => new AchievementClassName(),
    ];

    /**
     * Get all registered achievement classes.
     *
     * @return array<string, class-string<Achievement>>
     */
    public static function getAllAchievementClasses(): array
    {
        return array_keys(self::$achievements);
    }

    /**
     * Get all achievement instances.
     *
     * @return array<string, Achievement>
     */
    public static function getAllAchievementInstances(): array
    {
        return array_values(self::$achievements);
    }

    /**
     * Get a specific achievement instance by its ::class.
     *
     * @param string $className
     * @return Achievement|null
     */
    public static function getAchievement(string $className): ?Achievement
    {
        if (!isset(self::$achievements[$className])) {
            return null;
        }

        return self::$achievements[$className];
    }

    /**
     * Get an achievement by its ID.
     *
     * @param string $achievementId
     * @return Achievement|null
     */
    public static function getAchievementById(string $achievementId): ?Achievement
    {
        foreach (self::$achievements as $achievement) {
            if ($achievement->getId() === $achievementId) {
                return $achievement;
            }
        }
        return null;
    }
}
