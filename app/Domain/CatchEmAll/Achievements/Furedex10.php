<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;

class Furedex10 extends AFuredex implements HiddenIfLocked, LockedBy
{
    public function __construct()
    {
        parent::__construct(
            id: 'furedex_10',
            title: 'Fill the furédex II',
            description: 'You caught 10 species at least once. Congratulations!',
            task: 'Catch 10 species at least once.',
            icon: '👑',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 10;
    }

    /**
     * {@inheritDoc}
     */
    public function lockedBy(): array
    {
        return [
            'furedex_5',
        ];
    }
}
