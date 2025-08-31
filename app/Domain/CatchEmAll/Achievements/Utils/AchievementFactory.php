<?php

namespace App\Domain\CatchEmAll\Achievements\Utils;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\UserAchievement;
use App\Models\User;

class AchievementFactory
{
    /**
     * Create a new user achievement instance.
     *
     * @param User $user
     * @param string $achievementId
     * @return UserAchievement
     */
    public static function createUserAchievement(User $user, Achievement $achievement): UserAchievement
    {
        return UserAchievement::firstOrCreate([
            'user_id' => $user->id,
            'achievement_id' => $achievement->getId(),
            'progress' => 0,
        ]);
    }

    /**
     * Update the progress of a user achievement.
     *
     * @param User $user
     * @param Achievement $achievement
     * @param int $newProgress
     * @return UserAchievement
     */
    public static function updateUserAchievementProgress(User $user, Achievement $achievement, int $newProgress): UserAchievement
    {
        $userAchievement = UserAchievement::firstOrCreate([
            'user_id' => $user->id,
            'achievement_id' => $achievement->getId(),
        ]);

        $userAchievement->progress = $newProgress;
        $userAchievement->save();

        return $userAchievement;
    }


}
