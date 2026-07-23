<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class FirstCatch extends SimpleAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'first_catch',
            title: 'First Catch',
            description: 'You have successfully made your first catch.',
            task: 'Catch your first Fursuit.',
            icon: '🎣',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 1;
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
