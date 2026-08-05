<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\Expandable;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Nice extends SimpleAchievement implements Expandable
{
    public function __construct()
    {
        parent::__construct(
            id: 'nice',
            title: 'Nice',
            description: "Nice? Nice. What even is the number sixty nine? A meme? A number to laugh at? People around me, they laugh at this number mindlessly, not knowing the true bearing of its magnitude. But me? I know. I know the true strength of it. I know where it leads. It's a mistake. The number. The whole deal. Furries... they just... they see funny number... their brains perk up... they light up... one might say \"neuron activation\"... and that's yet another meme in itself. Have you ever considered why we meme? Why the memes exist? Why 69 in specific? Because the arabic glyphs kinda look like <at this point, the narrator's eyes go bluescreen and you hear garbled noises>??? So simple minded, the folk around here... so uncaring for the intricacies... what if someone's birthday was on 6.9.xxxx? Would you meme their special day just for your personal satisfaction? Would you take that away from them? Would you? You probably would, wontcha, I know you would.... You and I... we're not so different, Furry... we were both interested in coming to this event, you and I... and you've decided to catch 69 fursuiters... for what? for an achievement? For glory? For personal satisfaction? I know all of it... Go catch some more",
            task: 'Catch 69 Fursuits.',
            icon: '😏',
            isSecret: true,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 69;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's total catches (secret achievement at exactly 69 catches)
        $currentProgress = min($context->userTotalCatches, $this->getMaxProgress());

        return $currentProgress;
    }
}
