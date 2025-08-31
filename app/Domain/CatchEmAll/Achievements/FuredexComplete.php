<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class FuredexComplete implements Achievement
{
    private static $suitCount = 5;

    public function getId(): string
    {
        return 'furedex_complete';
    }

    public function getTile(): string
    {
        return 'Your Furédex is entirely complete Congratulations!';
    }

    public function getDescription(): string
    {
        return 'How have you even done this?';
    }

    public function getIcon(): string
    {
        return '👑';
    }

    public function getMaxProgress(): int
    {
        return self::$suitCount;
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

    /**
     * Override standard behaviour because it depends on the total catchable fursuit, which is a dynamic value.
     */
    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (!$context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Update the max suit count if the context indicates a new total
        if ($context->totalCatchableFursuits != self::$suitCount) {
            self::$suitCount = $context->totalCatchableFursuits;
        }

        // Return current progress based on user's unique fursuits caught
        $currentProgress = min($context->userUniqueFursuits, $this->getMaxProgress());

        // TODO: UPDATE && GRANT

        // Always override default behavior
        return -1;
    }
}
