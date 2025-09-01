<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Nice implements Achievement
{
    public function getId(): string
    {
        return 'nice';
    }

    public function getTile(): string
    {
        return 'Nice';
    }

    public function getDescription(): string
    {
        return 'Nice.';
    }

    public function getIcon(): string
    {
        return '😏';
    }

    public function getMaxProgress(): int
    {
        return 69;
    }

    public function isSecret(): bool
    {
        return true;
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

        // Return current progress based on user's total catches (secret achievement at exactly 69 catches)
        $currentProgress = min($context->userTotalCatches, $this->getMaxProgress());

        return $currentProgress;
    }
}
