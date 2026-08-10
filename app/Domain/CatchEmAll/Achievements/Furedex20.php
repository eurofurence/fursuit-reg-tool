<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;

class Furedex20 extends AFuredex implements HiddenIfLocked, LockedBy
{
    public function __construct()
    {
        parent::__construct(
            id: 'furedex_20',
            title: 'Fill the furédex III',
            description: 'You caught 20 species at least once. Congratulations!',
            task: 'Catch 20 species at least once.',
            icon: '👑',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 20;
    }

    /**
     * {@inheritDoc}
     */
    public function lockedBy(): array
    {
        return [
            'furedex_10',
        ];
    }
}
