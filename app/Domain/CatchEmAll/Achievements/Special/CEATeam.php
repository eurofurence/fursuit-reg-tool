<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class CEATeam extends SimpleAchievement implements SpecialAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'cea_team',
            title: 'Catch \'Em All Team',
            description: 'You found someone that made this game!',
            task: 'Find a member of the Catch \'Em All development team.',
            icon: '👑',
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
