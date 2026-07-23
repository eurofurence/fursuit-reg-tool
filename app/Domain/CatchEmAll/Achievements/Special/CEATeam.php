<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class CEATeam implements SpecialAchievement
{
    public function getId(): string
    {
        return 'cea_team';
    }

    public function getTile(): string
    {
        return 'Catch \'Em All Team';
    }

    public function getDescription(): string
    {
        return 'You found someone that made this game!';
    }

    public function getIcon(): string
    {
        return '👑';
    }

    public function getMaxProgress(): int
    {
        return 1;
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
        // This achievement can only be triggered by special code, not by catches
        if (! $context->isSpecialCodeTrigger() || $context->specialCodeType !== SpecialCodeType::CATCH_EM_ALL_TEAM) {
            return -1; // Ignore this update
        }

        // Return completion progress - achievement granting is handled by AchievementService
        return $this->getMaxProgress(); // Return 1 (completed)
    }

    public function getSpecialCode(): SpecialCodeType
    {
        return SpecialCodeType::CATCH_EM_ALL_TEAM;
    }
}
