<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class BugBountyHunter extends SimpleAchievement implements SpecialAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'bug_bounty_hunter',
            title: 'Bug Bounty Hunter',
            description: 'Thanks for the QA! Your contribution is noted.',
            task: 'Report a bug or issue to the development team.',
            icon: '🐛',
            isSecret: true,
            isOptional: true,
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
        if (! $context->isSpecialCodeTrigger() || $context->specialCodeType !== SpecialCodeType::BUG_BOUNTY) {
            return -1; // Ignore this update
        }

        // Return completion progress - achievement granting is handled by AchievementService
        return $this->getMaxProgress(); // Return 1 (completed)
    }

    public function getSpecialCode(): SpecialCodeType
    {
        return SpecialCodeType::BUG_BOUNTY;
    }
}
