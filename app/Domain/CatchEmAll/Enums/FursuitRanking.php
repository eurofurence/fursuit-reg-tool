<?php

namespace App\Domain\CatchEmAll\Enums;

enum FursuitRanking: string
{
    case BRONZE = 'bronze';
    case SILVER = 'silver';
    case GOLD = 'gold';
    case PLATINUM = 'platinum';
    case DIAMOND = 'diamond';

    public function getLabel(): string
    {
        return match ($this) {
            self::BRONZE => 'Bronze',
            self::SILVER => 'Silver',
            self::GOLD => 'Gold',
            self::PLATINUM => 'Platinum',
            self::DIAMOND => 'Diamond',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BRONZE => 'text-gray-600',
            self::SILVER => 'text-green-600',
            self::GOLD => 'text-blue-600',
            self::PLATINUM => 'text-purple-600',
            self::DIAMOND => 'text-orange-600',
        };
    }

    public function getBgColor(): string
    {
        return match ($this) {
            self::BRONZE => 'bg-gray-100',
            self::SILVER => 'bg-green-100',
            self::GOLD => 'bg-blue-100',
            self::PLATINUM => 'bg-purple-100',
            self::DIAMOND => 'bg-orange-100',
        };
    }

    public function getGradient(): string
    {
        return match ($this) {
            self::BRONZE => 'from-gray-400 to-gray-600',
            self::SILVER => 'from-green-400 to-green-600',
            self::GOLD => 'from-blue-400 to-blue-600',
            self::PLATINUM => 'from-purple-400 to-purple-600',
            self::DIAMOND => 'from-orange-400 to-yellow-500',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::BRONZE => '⚪',
            self::SILVER => '🟢',
            self::GOLD => '🔵',
            self::PLATINUM => '🟣',
            self::DIAMOND => '🟡',
        };
    }
}
