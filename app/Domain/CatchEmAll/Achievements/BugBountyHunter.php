<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class BugBountyHunter implements SpecialAchievement
{

    public function getId(): string
    {
        return 'bug_bounty_hunter';
    }


    public function getTile(): string
    {
        return 'Bug Bounty Hunter';
    }


    public function getDescription(): string
    {
        return 'Thanks for the QA! Your contribution is noted.';
    }


    public function getIcon(): string
    {
        return '🐛';
    }


    public function getMaxProgress(): int
    {
        return 1;
    }


    public function isSecret(): bool
    {
        return true;
    }


    public function isOptional(): bool
    {
        return true;
    }


    public function isHidden(): bool
    {
        return false;
    }


    public function updateAchievementProgress(AchievementUpdateContext $context): bool
    {
        // This achievement can only be triggered by special code, not by catches
        if (!$context->isSpecialCodeTrigger() || $context->specialCodeType !== SpecialCodeType::BUG_BOUNTY) {
            return false;
        }

        \App\Domain\CatchEmAll\Services\AchievementService::grantAchievement($context->user, $this);
        return true;
    }


    public function getSpecialCode(): SpecialCodeType
    {
        return SpecialCodeType::BUG_BOUNTY;
    }
}
