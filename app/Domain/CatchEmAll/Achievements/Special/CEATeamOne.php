<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

class CEATeamOne extends ACEATeam
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
}
