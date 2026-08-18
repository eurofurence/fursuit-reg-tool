<?php

namespace App\Domain\CatchEmAll\Achievements\Utils;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Interface\Expandable;
use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;
use App\Domain\CatchEmAll\Interface\ProgressInfo;
use App\Domain\CatchEmAll\Models\UserAchievement;
use App\Models\EventUser;
use App\Models\User;
use Cache;

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
        return Cache::rememberForever("user_achievements_{$eventUser->id}", function () use ($eventUser) {
            // Get all user achievements with their progress
            $userAchievements = UserAchievement::where('event_user_id', $eventUser->id)->get();

            // Get all registered achievements
            $allAchievements = AchievementRegister::getAllAchievementInstances();

            // ACHIEVEMENT SORTING
            $achievementsToRemove = [];
            $completedAchievements = [];
            foreach ($allAchievements as $achievement) {
                // Filter out hidden achievements
                if ($achievement->isHidden()) {
                    $achievementsToRemove[] = $achievement->getId();
                    continue;
                }
                $userAchievement = $userAchievements->firstWhere('achievement', $achievement->getId());

                // Check if achievement is completed
                $isCompleted = $userAchievement && $userAchievement->isCompleted();
                if ($isCompleted) {
                    $completedAchievements[] = $achievement->getId();
                    continue;
                }

                // Filter out secret achievements that haven't been earned yet
                if ($achievement->isSecret() && ! $isCompleted) {
                    $achievementsToRemove[] = $achievement->getId();
                    continue;
                }
            }

            foreach ($completedAchievements as $completedId) {
                $stacksOnId = AchievementRegister::getStacksOnAchievementID($completedId);
                if ($stacksOnId && \in_array($stacksOnId, $completedAchievements)) {
                    $achievementsToRemove[] = $completedId;
                }
            }

            // Remove hidden and secret achievements from the list
            $allAchievements = array_filter($allAchievements, fn(Achievement $achievement) => !\in_array($achievement->getId(), $achievementsToRemove));

            $result = [];

            foreach ($allAchievements as $achievement) {
                // Get user achievement record if it exists
                /**
                 * @var UserAchievement|null $userAchievement
                 */
                $userAchievement = $userAchievements->firstWhere('achievement', $achievement->getId());

                // Check if achievement is completed
                $isCompleted = $userAchievement && $userAchievement->isCompleted();

                // Get current progress (0 if no record exists)
                $currentProgress = $userAchievement ? $userAchievement->progress : 0;
                $maxProgress = $achievement->getMaxProgress();

                // Calculate progress percentage
                $progressPercentage = $maxProgress > 0 ? round(($currentProgress / $maxProgress) * 100, 2) : 0;

                // Get earned timestamp
                $earnedAt = $isCompleted && $userAchievement ? $userAchievement->earned_at : null;

                // Check if the achievement is locked by other achievements
                $isLocked = false;
                if ($achievement instanceof LockedBy) {
                    $lockedByAchievements = $achievement->lockedBy();
                    foreach ($lockedByAchievements as $lockedById) {
                        $lockedByUserAchievement = $userAchievements->firstWhere('achievement', $lockedById);
                        if (! $lockedByUserAchievement || ! $lockedByUserAchievement->isCompleted()) {
                            $isLocked = true;
                            break;
                        }
                    }
                }

                $additionalInfo = null;
                if ($achievement instanceof ProgressInfo && ! $isLocked && ! $isCompleted) {
                    $totalProgressInfo = $achievement->getTotalProgress();
                    $additionalInfo = [
                        'totalProgress' => $totalProgressInfo,
                        'currentProgress' => $achievement->getCurrentProgress($eventUser),
                    ];
                }

                // A hidden achievement must not ship its title and task in the page
                // payload: the frontend can mask them, but anyone reading the JSON
                // would still see what is left to find.
                $isMasked = $achievement instanceof HiddenIfLocked && $isLocked && ! $isCompleted;

                $result[] = [
                    'id' => $achievement->getId(),
                    'achievement' => $achievement->getId(), // Using ID as achievement identifier
                    'title' => $isMasked ? null : $achievement->getTitle(),
                    'description' => $isMasked ? null : $achievement->getDescription(),
                    'task' => $isMasked ? null : $achievement->getTask(),
                    'icon' => $achievement->getIcon(),
                    'completed' => $isCompleted,
                    'progress' => $isMasked ? 0 : $currentProgress,
                    'maxProgress' => $isMasked ? 0 : $maxProgress,
                    'progressPercentage' => $isMasked ? 0 : $progressPercentage,
                    'earnedAt' => $earnedAt,
                    'isSecret' => $achievement->isSecret(),
                    'isOptional' => $achievement->isOptional(),
                    'isLocked' => $isLocked,
                    'hiddenByLock' => $isMasked,
                    //'expandable' => $achievement instanceof Expandable, // Deprecated: frontend expends all achievements by default, no need to mark them as expandable
                    'progressDetail' => $additionalInfo,
                    'tier' => $achievement->getTier()->value,
                ];
            }



            return array_values($result);
        });
    }

    public static function getUserAchievementStats(EventUser $eventUser): array
    {
        return Cache::rememberForever("user_achievement_stats_{$eventUser->id}", function () use ($eventUser) {
            $userAchievements = UserAchievement::where('event_user_id', $eventUser->id)->get();
            $allAchievements = AchievementRegister::getAllAchievementInstances();

            $totalAchievements = $totalAchievementsWithOptional = $earnedAchievements = $earnedOptionalAchievements = 0;

            foreach ($allAchievements as $achievement) {
                // Filter out hidden achievements
                if ($achievement->isHidden()) {
                    continue;
                }

                // Filter out secret achievements that haven't been earned yet
                $userAchievement = $userAchievements->firstWhere('achievement', $achievement->getId());
                $isCompleted = $userAchievement && $userAchievement->isCompleted();
                if ($achievement->isSecret() && ! $isCompleted) {
                    continue;
                }

                // Count total achievements
                if (!$achievement->isOptional()) {
                    $totalAchievements++;
                }
                $totalAchievementsWithOptional++;

                // Count earned achievements
                if ($isCompleted) {
                    if ($achievement->isOptional()) {
                        $earnedOptionalAchievements++;
                    } else {
                        $earnedAchievements++;
                    }
                }
            }

            return [
                'total' => $totalAchievements,
                'earned' => $earnedAchievements,
                'earnedOptional' => $earnedOptionalAchievements,
                'totalWithOptional' => $totalAchievementsWithOptional,
            ];
        });
    }
}
