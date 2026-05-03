<?php

namespace App\Domain\CatchEmAll\Achievements\Utils;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\UserAchievement;
use App\Models\EventUser;
use App\Models\User;

class AchievementFactory
{
    /**
     * Create a new user achievement instance.
     */
    public static function createUserAchievement(EventUser $eventUser, Achievement $achievement): UserAchievement
    {
        return UserAchievement::firstOrCreate([
            'event_user_id' => $eventUser->id,
            'achievement' => $achievement->getId(),
            'progress' => 0,
        ]);
    }

    /**
     * Update the progress of a user achievement.
     */
    public static function updateUserAchievementProgress(EventUser $eventUser, Achievement $achievement, int $newProgress): UserAchievement
    {
        $existing = UserAchievement::firstOrCreate([
            'event_user_id' => $eventUser->id,
            'achievement' => $achievement->getId(),
        ], [
            'progress' => 0,
        ]);

        $existing->progress = min($newProgress, $achievement->getMaxProgress());

        if ($existing->progress >= $achievement->getMaxProgress() && ! $existing->earned_at) {
            $existing->earned_at = now();
        }

        $existing->save();

        return $existing;
    }

    /**
     * Grant an achievement to a user.
     */
    public static function grantUserAchievement(EventUser $eventUser, Achievement $achievement): UserAchievement
    {
        $userAchievement = UserAchievement::firstOrCreate([
            'event_user_id' => $eventUser->id,
            'achievement' => $achievement->getId(),
            'progress' => $achievement->getMaxProgress(),
        ]);

        $userAchievement->earned_at = now();
        $userAchievement->save();

        return $userAchievement;
    }

    /**
     * Get all achievement data for a user with progress and completion status.
     * Filters out hidden achievements and secret achievements that haven't been earned yet.
     */
    public static function getUserAchievementData(EventUser $eventUser): array
    {
        // Get all user achievements with their progress
        $userAchievements = UserAchievement::where('event_user_id', $eventUser->id)->get();

        // Get all registered achievements
        $allAchievements = AchievementRegister::getAllAchievementInstances();

        $result = [];

        foreach ($allAchievements as $achievement) {
            // Filter out hidden achievements
            if ($achievement->isHidden()) {
                continue;
            }

            // Get user achievement record if it exists
            /**
             * @var UserAchievement|null $userAchievement
             */
            $userAchievement = $userAchievements->firstWhere('achievement', $achievement->getId());

            // Check if achievement is completed
            $isCompleted = $userAchievement && $userAchievement->isCompleted();

            // Filter out secret achievements that haven't been earned yet
            if ($achievement->isSecret() && ! $isCompleted) {
                continue; // Skip this achievement instead of throwing an exception
            }

            // Get current progress (0 if no record exists)
            $currentProgress = $userAchievement ? $userAchievement->progress : 0;
            $maxProgress = $achievement->getMaxProgress();

            // Calculate progress percentage
            $progressPercentage = $maxProgress > 0 ? round(($currentProgress / $maxProgress) * 100, 2) : 0;

            // Get earned timestamp
            $earnedAt = $isCompleted && $userAchievement ? $userAchievement->earned_at : null;

            $result[] = [
                'id' => $achievement->getId(),
                'achievement' => $achievement->getId(), // Using ID as achievement identifier
                'title' => $achievement->getTile(),
                'description' => $achievement->getDescription(),
                'icon' => $achievement->getIcon(),
                'completed' => $isCompleted,
                'progress' => $currentProgress,
                'maxProgress' => $maxProgress,
                'progressPercentage' => $progressPercentage,
                'earnedAt' => $earnedAt,
                'isSecret' => $achievement->isSecret(),
                'isOptional' => $achievement->isOptional(),
            ];
        }

        return $result;
    }
}
