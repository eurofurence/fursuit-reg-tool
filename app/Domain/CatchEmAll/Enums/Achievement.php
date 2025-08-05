<?php

namespace App\Domain\CatchEmAll\Enums;

enum Achievement: string
{
    case FIRST_CATCH = 'first_catch';
    case SPECIES_COLLECTOR = 'species_collector';
    case RARE_HUNTER = 'rare_hunter';
    case EPIC_SEEKER = 'epic_seeker';
    case LEGENDARY_MASTER = 'legendary_master';
    case SPEED_DEMON = 'speed_demon';
    case SOCIAL_BUTTERFLY = 'social_butterfly';
    case DEDICATION = 'dedication';
    case COMPLETIONIST = 'completionist';
    case CHEATER = 'cheater';

    public function getTitle(): string
    {
        return match ($this) {
            self::FIRST_CATCH => 'First Steps',
            self::SPECIES_COLLECTOR => 'Species Collector',
            self::RARE_HUNTER => 'Rare Hunter',
            self::EPIC_SEEKER => 'Epic Seeker',
            self::LEGENDARY_MASTER => 'Legendary Master',
            self::SPEED_DEMON => 'Speed Demon',
            self::SOCIAL_BUTTERFLY => 'Social Butterfly',
            self::DEDICATION => 'Dedicated Catcher',
            self::COMPLETIONIST => 'Completionist',
            self::CHEATER => 'Suspicious Activity',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::FIRST_CATCH => 'Caught your first fursuiter!',
            self::SPECIES_COLLECTOR => 'Caught 10 different species',
            self::RARE_HUNTER => 'Caught 5 rare fursuiters',
            self::EPIC_SEEKER => 'Caught 3 epic fursuiters',
            self::LEGENDARY_MASTER => 'Caught a legendary fursuiter',
            self::SPEED_DEMON => 'Caught 10 fursuiters in one hour',
            self::SOCIAL_BUTTERFLY => 'Caught 50 different fursuiters',
            self::DEDICATION => 'Caught fursuiters on 3 different days',
            self::COMPLETIONIST => 'Caught all available fursuiters',
            self::CHEATER => 'Detected suspicious activity',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::FIRST_CATCH => '🌟',
            self::SPECIES_COLLECTOR => '📚',
            self::RARE_HUNTER => '🎯',
            self::EPIC_SEEKER => '💎',
            self::LEGENDARY_MASTER => '👑',
            self::SPEED_DEMON => '⚡',
            self::SOCIAL_BUTTERFLY => '🦋',
            self::DEDICATION => '🏆',
            self::COMPLETIONIST => '💯',
            self::CHEATER => '⚠️',
        };
    }

    public function isHidden(): bool
    {
        return match ($this) {
            self::CHEATER => true,
            default => false,
        };
    }
}