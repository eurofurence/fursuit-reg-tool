<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Nice extends SimpleAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'nice',
            title: 'Nice',
            description: 'Nice ^^.',
            task: 'Catch 69 Fursuits.',
            icon: '😏',
            isSecret: true,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 69;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's total catches (secret achievement at exactly 69 catches)
        $currentProgress = min($context->userTotalCatches, $this->getMaxProgress());

        return $currentProgress;
    }
}
