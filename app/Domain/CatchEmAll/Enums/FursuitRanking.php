<?php

namespace App\Domain\CatchEmAll\Enums;

/**
 * How sought after a fursuit is, from how many people have caught it.
 *
 * The ranking names describe how much attention a fursuit attracts during the
 * event, from Novice through Legend.
 *
 * A ranking therefore starts at Novice for everyone and climbs during the event.
 * How many of that species are registered is a separate figure, shown as a plain
 * count where it is useful rather than folded into this scale.
 */
enum FursuitRanking: string
{
    case NOVICE = 'novice';
    case REGULAR = 'regular';
    case FLUFFY = 'fluffy';
    case EXTRAORDINAIRE = 'extraordinaire';
    case LEGEND = 'legend';

    public static function fromCatchCount(int $catches): self
    {
        return match (true) {
            $catches >= config('fcea.fursuit_ranking_threshold_legend') => self::LEGEND,
            $catches >= config('fcea.fursuit_ranking_threshold_extraordinaire') => self::EXTRAORDINAIRE,
            $catches >= config('fcea.fursuit_ranking_threshold_fluffy') => self::FLUFFY,
            $catches >= config('fcea.fursuit_ranking_threshold_regular') => self::REGULAR,
            default => self::NOVICE,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::NOVICE => 'Novice',
            self::REGULAR => 'Regular',
            self::FLUFFY => 'Fluffy',
            self::EXTRAORDINAIRE => 'Extraordinaire',
            self::LEGEND => 'Legend',
        };
    }

    /** Hex, not a Tailwind class: the frontend paints borders, washes and bars with it. */
    public function getColor(): string
    {
        return match ($this) {
            self::NOVICE => '#6f9fd8',
            self::REGULAR => '#cf8b52',
            self::FLUFFY => '#b9c4cf',
            self::EXTRAORDINAIRE => '#d9a520',
            self::LEGEND => '#c3aef5',
        };
    }

    /** lucide icon name; the emoji these used to be rendered differently on every device. */
    public function getIcon(): string
    {
        return match ($this) {
            self::NOVICE => 'circle',
            self::REGULAR => 'star',
            self::FLUFFY => 'medal',
            self::EXTRAORDINAIRE => 'sparkles',
            self::LEGEND => 'gem',
        };
    }

    /** Highest first, for the filter row. */
    public static function ranked(): array
    {
        return [self::LEGEND, self::EXTRAORDINAIRE, self::FLUFFY, self::REGULAR, self::NOVICE];
    }
}
