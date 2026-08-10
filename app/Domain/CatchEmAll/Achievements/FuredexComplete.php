<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;

use function count;

class FuredexComplete extends AFuredex implements HiddenIfLocked, LockedBy
{
    public function __construct()
    {
        parent::__construct(
            id: 'furedex_complete',
            title: 'Your Furédex is complete!',
            description: 'You caught all species at least once. Congratulations!',
            task: 'Catch all species at least once.',
            icon: '👑',
            isSecret: false,
            isOptional: true,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return count($this->getSpecies());
    }

    /**
     * {@inheritDoc}
     */
    public function lockedBy(): array
    {
        return [
            'furedex_20',
        ];
    }
}
