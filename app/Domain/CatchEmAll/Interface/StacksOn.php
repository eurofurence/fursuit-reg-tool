<?php

namespace App\Domain\CatchEmAll\Interface;

interface StacksOn extends Achievement
{
    /**
     * Get the achievement ID that this achievement stacks on.
     *
     * Removes the marked achievement, if this is earned, and adds the new achievement instead. This is used for achievements that have multiple tiers, where earning a higher tier replaces the lower tier.
     *
     * @return string The achievement ID that this achievement stacks on.
     */
    public function stacksOn(): string;
}
