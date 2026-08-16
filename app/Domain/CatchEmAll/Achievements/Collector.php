<?php

namespace App\Domain\CatchEmAll\Achievements;

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


    public function __construct(string $id, string $title, string $description, string $task, int $maxProgress, array $lockedByIds = [], string $stacksOnId = '', bool $isSecret = false, bool $isOptional = false, bool $isHidden = false)
    {
        parent::__construct($id, $title, $description, $task, '',$isSecret, $isOptional, $isHidden);
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
        $definitions = [
            ['first_catch', 'First Catch', 'You have successfully made your first catch.', 'Catch your first Fursuit.', 1, [], ''],
            ['collector', 'Collector', 'You are an aspiring collector.', 'Catch 10 Fursuits.', 10, ['first_catch'], 'first_catch'],
            ['curator', 'Curator', 'Your reputation as a collector is growing.', 'Catch 20 Fursuits.', 20, ['collector'], 'collector'],
            ['gotta_catch_em_all', 'Gotta Catch \'Em All', 'There is still something more to do.', 'Catch 50 Fursuits.', 50, ['curator'], 'curator'],
            ['archivist', 'Archivist', 'Your dedication is clear.', 'Catch 100 Fursuits.', 100, ['gotta_catch_em_all'], 'gotta_catch_em_all'],
            ['the_legendary_151', 'The Legendary 151', 'Just like a certain little mouse.', 'Catch 151 Fursuits.', 151, ['archivist'], 'archivist'],
            ['nice', 'Nice', "Nice? Nice. What even is the number sixty nine? A meme? A number to laugh at? People around me, they laugh at this number mindlessly, not knowing the true bearing of its magnitude. But me? I know. I know the true strength of it. I know where it leads. It's a mistake. The number. The whole deal. Furries... they just... they see funny number... their brains perk up... they light up... one might say \"neuron activation\"... and that's yet another meme in itself. Have you ever considered why we meme? Why the memes exist? Why 69 in specific? Because the arabic glyphs kinda look like <at this point, the narrator's eyes go bluescreen and you hear garbled noises>??? So simple minded, the folk around here... so uncaring for the intricacies... what if someone's birthday was on 6.9.xxxx? Would you meme their special day just for your personal satisfaction? Would you take that away from them? Would you? You probably would, wontcha, I know you would.... You and I... we're not so different, Furry... we were both interested in coming to this event, you and I... and you've decided to catch 69 fursuiters... for what? for an achievement? For glory? For personal satisfaction? I know all of it... Go catch some more", 'Catch 69 Fursuits.', 69, [], '', true],
        ];

        return array_map(
            fn (array $d) => new self(id: $d[0], title: $d[1], description: $d[2], task: $d[3], maxProgress: $d[4], lockedByIds: $d[5], stacksOnId: $d[6], isSecret: $d[7] ?? false, isOptional: $d[8] ?? false, isHidden: $d[9] ?? false),
            $definitions
        );
    }
}
