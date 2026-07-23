<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class TheLegendary151 extends SimpleAchievement implements HiddenIfLocked, LockedBy
{
    public function __construct()
    {
        parent::__construct(
            id: 'the_legendary_151',
            title: 'The Legendary 151',
            description: 'Just like a certain little mouse.',
            task: 'Catch 151 Fursuits.',
            icon: '⚡',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 151;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's unique fursuits caught
        $currentProgress = min($context->userUniqueFursuits, $this->getMaxProgress());

        return $currentProgress;
    }

    public function lockedBy(): array
    {
        return [
            'archivist',
        ];
    }
}
