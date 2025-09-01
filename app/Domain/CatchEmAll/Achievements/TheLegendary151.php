<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class TheLegendary151 implements Achievement
{
    public function getId(): string
    {
        return 'the_legendary_151';
    }

    public function getTile(): string
    {
        return 'The Legendary 151';
    }

    public function getDescription(): string
    {
        return 'Just like a certain little mouse.';
    }

    public function getIcon(): string
    {
        return '⚡';
    }

    public function getMaxProgress(): int
    {
        return 151;
    }

    public function isSecret(): bool
    {
        return false;
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function isHidden(): bool
    {
        return false;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (!$context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's unique fursuits caught
        $currentProgress = min($context->userUniqueFursuits, $this->getMaxProgress());

        return $currentProgress;
    }
}
