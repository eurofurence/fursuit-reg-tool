<?php

namespace App\Domain\CatchEmAll\Enums;

/**
 * How sought after a fursuit is, from how many people have caught it.
 *
 * This replaces the old Common-to-Legendary scale, which measured the same thing
 * but called it rarity: a suiter everybody photographs is popular, not rare, and
 * a species nobody else brought is rare however few people find it. Bronze to
 * Diamond says what the number actually is, so nothing has to be explained away.
 *
 * A ranking therefore starts at Bronze for everyone and climbs during the event.
 * How many of that species are registered is a separate figure, shown as a plain
 * count where it is useful rather than folded into this scale.
 */
enum FursuitRanking: string
{
    case BRONZE = 'bronze';
    case SILVER = 'silver';
    case GOLD = 'gold';
    case PLATINUM = 'platinum';
    case DIAMOND = 'diamond';

    public static function fromCatchCount(int $catches): self
    {
        return match (true) {
            $catches >= config('fcea.fursuit_ranking_threshold_diamond') => self::DIAMOND,
            $catches >= config('fcea.fursuit_ranking_threshold_platinum') => self::PLATINUM,
            $catches >= config('fcea.fursuit_ranking_threshold_gold') => self::GOLD,
            $catches >= config('fcea.fursuit_ranking_threshold_silver') => self::SILVER,
            default => self::BRONZE,
        };
    }

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

    /** Hex, not a Tailwind class: the frontend paints borders, washes and bars with it. */
    public function getColor(): string
    {
        return match ($this) {
            self::BRONZE => '#cf8b52',
            self::SILVER => '#b9c4cf',
            self::GOLD => '#d9a520',
            self::PLATINUM => '#6f9fd8',
            self::DIAMOND => '#5fd0e0',
        };
    }

    /** lucide icon name; the emoji these used to be rendered differently on every device. */
    public function getIcon(): string
    {
        return match ($this) {
            self::BRONZE => 'circle',
            self::SILVER => 'star',
            self::GOLD => 'medal',
            self::PLATINUM => 'sparkles',
            self::DIAMOND => 'gem',
        };
    }

    /** Highest first, for the filter row. */
    public static function ranked(): array
    {
        return [self::DIAMOND, self::PLATINUM, self::GOLD, self::SILVER, self::BRONZE];
    }
}
