<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;

class CEATeamThree extends ACEATeam implements HiddenIfLocked, LockedBy
{
    public function __construct()
    {
        parent::__construct(
            id: 'cea_team_three',
            title: 'Catch \'Em All Team II',
            description: 'You found three of us! Can you also find the remaining team?',
            task: 'You found one, but can you find two more of us?',
            icon: '👑',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 3;
    }

    /**
     * {@inheritDoc}
     */
    public function lockedBy(): array
    {
        return ['cea_team'];
    }
}
