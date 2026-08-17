<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Enums\AchievementsTier;
use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\AchievementSeries;
use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;
use App\Domain\CatchEmAll\Interface\StacksOn;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Collector extends SimpleAchievement implements LockedBy, AchievementSeries, HiddenIfLocked, StacksOn
{
    protected int $maxProgress;
    protected array $lockedByIds;
    protected string $stacksOnId;


    public function __construct(string $id, string $title, string $description, string $task, int $maxProgress, array $lockedByIds = [], string $stacksOnId = '', bool $isSecret = false, bool $isOptional = false, bool $isHidden = false, AchievementsTier $tier = AchievementsTier::TIER_1)
    {
        parent::__construct($id, $title, $description, $task, '', isSecret: $isSecret, isOptional: $isOptional, isHidden: $isHidden, tier: $tier);
        $this->maxProgress = $maxProgress;
        $this->lockedByIds = $lockedByIds;
        $this->stacksOnId = $stacksOnId;
    }

    public function lockedBy(): array
    {
        return $this->lockedByIds;
    }

    public function getMaxProgress(): int
    {
        return $this->maxProgress;
    }

    public function stacksOn(): string
    {
        return $this->stacksOnId;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Always override default behavior
        return min($context->userTotalCatches, $this->getMaxProgress());
    }

    public static function getAchievements(): array
    {
        $achievements = [];

        $achievements[] = new self(id: 'first_catch', title: 'First Catch', description: 'You have successfully made your first catch.', task: 'Catch your first Fursuit.', maxProgress: 1, tier: AchievementsTier::TIER_1);
        $achievements[] = new self(id: 'collector', title: 'Collector', description: 'You are an aspiring collector.', task: 'Catch 10 Fursuits.', maxProgress: 10, lockedByIds: ['first_catch'], stacksOnId: 'first_catch', tier: AchievementsTier::TIER_1);
        $achievements[] = new self(id: 'curator', title: 'Curator', description: 'Your reputation as a collector is growing.', task: 'Catch 20 Fursuits.', maxProgress: 20, lockedByIds: ['collector'], stacksOnId: 'collector', tier: AchievementsTier::TIER_2);
        $achievements[] = new self(id: 'gotta_catch_em_all', title: 'Gotta Catch \'Em All', description: 'There is still something more to do.', task: 'Catch 50 Fursuits.', maxProgress: 50, lockedByIds: ['curator'], stacksOnId: 'curator', tier: AchievementsTier::TIER_3);
        $achievements[] = new self(id: 'archivist', title: 'Archivist', description: 'Your dedication is clear.', task: 'Catch 100 Fursuits.', maxProgress: 100, lockedByIds: ['gotta_catch_em_all'], stacksOnId: 'gotta_catch_em_all', tier: AchievementsTier::TIER_4);
        $achievements[] = new self(id: 'the_legendary_151', title: 'The Legendary 151', description: 'Just like a certain little mouse.', task: 'Catch 151 Fursuits.', maxProgress: 151, lockedByIds: ['archivist'], stacksOnId: 'archivist', tier: AchievementsTier::TIER_5);
        $achievements[] = new self(id: 'nice', title: 'Nice', description: "Nice? Nice. What even is the number sixty nine? A meme? A number to laugh at? People around me, they laugh at this number mindlessly, not knowing the true bearing of its magnitude. But me? I know. I know the true strength of it. I know where it leads. It's a mistake. The number. The whole deal. Furries... they just... they see funny number... their brains perk up... they light up... one might say \"neuron activation\"... and that's yet another meme in itself. Have you ever considered why we meme? Why the memes exist? Why 69 in specific? Because the arabic glyphs kinda look like <at this point, the narrator's eyes go bluescreen and you hear garbled noises>??? So simple minded, the folk around here... so uncaring for the intricacies... what if someone's birthday was on 6.9.xxxx? Would you meme their special day just for your personal satisfaction? Would you take that away from them? Would you? You probably would, wontcha, I know you would.... You and I... we're not so different, Furry... we were both interested in coming to this event, you and I... and you've decided to catch 69 fursuiters... for what? for an achievement? For glory? For personal satisfaction? I know all of it... Go catch some more", task: 'Catch 69 Fursuits.', maxProgress: 69, isSecret: true, isOptional: true, tier: AchievementsTier::TIER_69);

        return $achievements;
    }
}
