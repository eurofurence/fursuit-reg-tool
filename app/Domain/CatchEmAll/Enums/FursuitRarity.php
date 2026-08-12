<?php

namespace App\Domain\CatchEmAll\Enums;

enum FursuitRarity: string
{
    case COMMON = 'common';
    case UNCOMMON = 'uncommon';
    case RARE = 'rare';
    case EPIC = 'epic';
    case LEGENDARY = 'legendary';

    /**
     * Rarity from how many fursuits of that species are at the event.
     *
     * Fewer of a species means rarer, which is the opposite direction to the old
     * rule: that one counted how often a fursuit had been caught, so it measured
     * fame and read as all-Common on the first morning. See SpeciesRarityService.
     */
    public static function fromSpeciesPopulation(int $population): self
    {
        return match (true) {
            $population <= config('fcea.rarity_population_legendary') => self::LEGENDARY,
            $population <= config('fcea.rarity_population_epic') => self::EPIC,
            $population <= config('fcea.rarity_population_rare') => self::RARE,
            $population <= config('fcea.rarity_population_uncommon') => self::UNCOMMON,
            default => self::COMMON,
        };
    }

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

    /** Hex, not a Tailwind class: the frontend paints borders, washes and bars with it. */
    public function getColor(): string
    {
        return match ($this) {
            self::COMMON => '#7d90a6',
            self::UNCOMMON => '#46b06a',
            self::RARE => '#3f8fe0',
            self::EPIC => '#a35fd6',
            self::LEGENDARY => '#e0a020',
        };
    }

    /** lucide icon name; the emoji these used to be rendered differently on every device. */
    public function getIcon(): string
    {
        return match ($this) {
            self::COMMON => 'book-open',
            self::UNCOMMON => 'star',
            self::RARE => 'sparkles',
            self::EPIC => 'gem',
            self::LEGENDARY => 'crown',
        };
    }

    /** Ordered rarest first, for the filter row. */
    public static function ranked(): array
    {
        return [self::LEGENDARY, self::EPIC, self::RARE, self::UNCOMMON, self::COMMON];
    }
}
