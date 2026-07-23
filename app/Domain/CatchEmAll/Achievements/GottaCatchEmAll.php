<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class GottaCatchEmAll extends SimpleAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'gotta_catch_em_all',
            title: 'Gotta Catch \'Em All',
            description: 'There is still something more to do.',
            task: 'Catch 50 Fursuits.',
            icon: '💯',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 50;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's total catches
        $currentProgress = min($context->userTotalCatches, $this->getMaxProgress());

        return $currentProgress;
    }
}
