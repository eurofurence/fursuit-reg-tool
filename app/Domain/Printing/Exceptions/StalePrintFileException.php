<?php

namespace App\Domain\Printing\Exceptions;

use App\Models\Badge\Badge;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Raised when a batch would have contained a badge whose artwork is missing or
 * no longer matches the order behind it.
 *
 * Invalidation clears the fingerprint instead of re-rendering, so between an
 * attendee's edit and the next generation pass a badge has no usable file. A
 * batch built from those badges would print last week's artwork and nobody
 * would find out until the attendee looked at the card at pickup.
 */
class StalePrintFileException extends RuntimeException
{
    /** @var Collection<int, Badge> */
    public Collection $badges;

    /**
     * @param  Collection<int, Badge>  $badges
     */
    public static function for(Collection $badges): self
    {
        $names = $badges
            ->map(fn (Badge $badge) => $badge->custom_id ?: "badge #{$badge->id}")
            ->implode(', ');

        $exception = new self(
            "Cannot build a batch: the print file is missing or out of date for {$names}. "
            .'Run `php artisan badges:generate-print-files` and build the batch again.'
        );

        $exception->badges = $badges;

        return $exception;
    }
}
