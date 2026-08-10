<?php

namespace App\Domain\CatchEmAll\Achievements;

class Furedex5 extends AFuredex
{
    public function __construct()
    {
        parent::__construct(
            id: 'furedex_5',
            title: 'Fill the furédex I',
            description: 'You caught 5 species at least once. Congratulations!',
            task: 'Catch 5 species at least once.',
            icon: '👑',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 5;
    }
}
