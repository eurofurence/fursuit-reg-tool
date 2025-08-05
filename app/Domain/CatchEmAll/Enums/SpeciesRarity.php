<?php

namespace App\Domain\CatchEmAll\Enums;

enum SpeciesRarity: string
{
    case COMMON = 'common';
    case UNCOMMON = 'uncommon';
    case RARE = 'rare';
    case EPIC = 'epic';
    case LEGENDARY = 'legendary';

    public function getLabel(): string
    {
        return match ($this) {
            self::COMMON => 'Common',
            self::UNCOMMON => 'Uncommon',
            self::RARE => 'Rare',
            self::EPIC => 'Epic',
            self::LEGENDARY => 'Legendary',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::COMMON => 'text-gray-600',
            self::UNCOMMON => 'text-green-600',
            self::RARE => 'text-blue-600',
            self::EPIC => 'text-purple-600',
            self::LEGENDARY => 'text-orange-600',
        };
    }

    public function getBgColor(): string
    {
        return match ($this) {
            self::COMMON => 'bg-gray-100',
            self::UNCOMMON => 'bg-green-100',
            self::RARE => 'bg-blue-100',
            self::EPIC => 'bg-purple-100',
            self::LEGENDARY => 'bg-orange-100',
        };
    }

    public function getGradient(): string
    {
        return match ($this) {
            self::COMMON => 'from-gray-400 to-gray-600',
            self::UNCOMMON => 'from-green-400 to-green-600',
            self::RARE => 'from-blue-400 to-blue-600',
            self::EPIC => 'from-purple-400 to-purple-600',
            self::LEGENDARY => 'from-orange-400 to-yellow-500',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::COMMON => '⚪',
            self::UNCOMMON => '🟢',
            self::RARE => '🔵',
            self::EPIC => '🟣',
            self::LEGENDARY => '🟡',
        };
    }

    public function getPoints(): int
    {
        return match ($this) {
            self::COMMON => 1,
            self::UNCOMMON => 2,
            self::RARE => 5,
            self::EPIC => 10,
            self::LEGENDARY => 25,
        };
    }
}