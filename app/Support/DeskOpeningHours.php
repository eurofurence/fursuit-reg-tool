<?php

namespace App\Support;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * The on-site badge desk's opening hours.
 *
 * A short list of rows - a date, a start time, an end time, an optional note - stored on
 * the event (`events.desk_opening_hours`) and read straight back by the public pickup
 * page. The desk is staffed to that event's schedule, so the hours belong beside
 * `pickup_booths` on the same row rather than in config.
 *
 * DATES, NOT WEEKDAYS. A row is one calendar day, `Y-m-d`, because a convention is a
 * handful of specific dates and "Wednesday" is ambiguous the moment an event spans two
 * of them or an attendee reads the page a year later. The weekday an attendee actually
 * wants to see is derived from the date at render time, so it can never disagree with it.
 *
 * `opens` and `closes` are 24h `H:i` strings, which is what an `<input type="time">`
 * produces and what a template can print without a timezone question. There is no
 * built-in default: an event with no hours publishes none, and the pickup page stays
 * quiet rather than promising a time nobody staffed.
 */
final class DeskOpeningHours
{
    /** Cap on how many rows an event may publish, so a paste cannot fill the page. */
    public const MAX_ROWS = 20;

    /**
     * The hours for an event, normalized, possibly empty.
     *
     * @return list<array{date: string, opens: string, closes: string, note: string|null}>
     */
    public static function forEvent(?Event $event): array
    {
        $configured = $event?->desk_opening_hours;

        return is_array($configured) ? self::normalize($configured) : [];
    }

    /**
     * Coerce a stored or submitted list into the shape the frontend renders.
     *
     * Rows without a real date or without both times are dropped rather than rendered
     * half empty: "Friday –" on a public page reads as a bug, and a missing row reads as
     * a day the desk did not publish.
     *
     * Sorted by date, unlike the booth list which keeps its typed order: days have one
     * correct order and it is not the order somebody happened to add the rows in.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{date: string, opens: string, closes: string, note: string|null}>
     */
    public static function normalize(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = self::date($row['date'] ?? null);
            $opens = self::time($row['opens'] ?? null);
            $closes = self::time($row['closes'] ?? null);

            if ($date === null || $opens === null || $closes === null) {
                continue;
            }

            $normalized[] = [
                'date' => $date,
                'opens' => $opens,
                'closes' => $closes,
                'note' => self::text($row['note'] ?? null, 120),
            ];

            if (count($normalized) === self::MAX_ROWS) {
                break;
            }
        }

        usort($normalized, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $normalized;
    }

    /**
     * "10:00 – 18:00", the one string both the panel and the public page print.
     *
     * @param  array{opens: string, closes: string}  $row
     */
    public static function range(array $row): string
    {
        return $row['opens'].' – '.$row['closes'];
    }

    /**
     * A real calendar day as `Y-m-d`, or null.
     *
     * Parsed rather than pattern-matched, so "2026-09-31" is refused instead of stored as
     * a date that does not exist.
     */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $limit);
    }

    /**
     * A 24h `H:i` time, or null. `9:5` is accepted and returned as `09:05`, because an
     * operator typing into a plain text field should not lose their row to a zero.
     */
    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $match)) {
            return null;
        }

        $hours = (int) $match[1];
        $minutes = (int) $match[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
