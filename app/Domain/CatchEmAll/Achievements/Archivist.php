<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Archivist extends SimpleAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'archivist',
            title: 'Archivist',
            description: 'Your dedication is clear.',
            task: 'Catch 100 Fursuits.',
            icon: '📚',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 100;
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
