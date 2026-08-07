<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;

use function count;

class CEATeamAll extends ACEATeam implements HiddenIfLocked, LockedBy
{
    public function __construct()
    {
        parent::__construct(
            id: 'cea_team_all',
            title: 'Catch \'Em All Team III',
            description: 'You found all of us! Congratulations!',
            task: 'Now find the rest of us!',
            icon: '👑',
            isSecret: false,
            isOptional: true,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return count($this->getNames());
    }

    /**
     * {@inheritDoc}
     */
    public function lockedBy(): array
    {
        return ['cea_team_three'];
    }
}
