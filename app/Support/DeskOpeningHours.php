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
 *
 * A row may also carry `reminds_at`: the time of day that day's pickup reminder goes out to
 * everybody still holding an uncollected badge. It is the desk's schedule, so it is stored
 * beside the desk's hours rather than in config, and it is optional per day - a day with no
 * time set sends nothing. The first published day never sends, whatever it carries: badges are
 * being handed out for the first time that day and nobody is late yet. See
 * `remindableRows()` and `dueReminder()`, which are the only two readers that matter.
 */
final class DeskOpeningHours
{
    /** Cap on how many rows an event may publish, so a paste cannot fill the page. */
    public const MAX_ROWS = 20;

    /**
     * The hours for an event, normalized, possibly empty.
     *
     * @return list<array{date: string, opens: string, closes: string, note: string|null, reminds_at: string|null}>
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
     * @return list<array{date: string, opens: string, closes: string, note: string|null, reminds_at: string|null}>
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
                // Optional, and dropped rather than defaulted when it is not a real time: a
                // reminder nobody scheduled must never be invented, since the mail goes to
                // every attendee still holding an uncollected badge.
                'reminds_at' => self::time($row['reminds_at'] ?? null),
            ];

            if (count($normalized) === self::MAX_ROWS) {
                break;
            }
        }

        usort($normalized, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $normalized;
    }

    /**
     * The first day the desk publishes hours for, `Y-m-d`, or null when it publishes none.
     *
     * `forEvent()` sorts by date, so the first row is the earliest one regardless of the
     * order an operator typed them in. This is the day the booth split applies to.
     */
    public static function firstDate(?Event $event): ?string
    {
        return self::forEvent($event)[0]['date'] ?? null;
    }

    /**
     * Whether the desk is open at this moment.
     *
     * Answered from the published rows and nothing else: a desk with no hours is never
     * reported open, because the alternative is a nav badge that sends someone across
     * the hall on a guess. Times are the event's local wall clock, which is what an
     * operator typed and what an attendee reads off their phone.
     */
    public static function isOpenNow(?Event $event): bool
    {
        $now = CarbonImmutable::now();
        $today = $now->format('Y-m-d');
        $time = $now->format('H:i');

        foreach (self::forEvent($event) as $row) {
            if ($row['date'] === $today && $time >= $row['opens'] && $time < $row['closes']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows an attendee can still act on: today and every published day after it.
     *
     * A reminder mail is read now, so a day that has already closed is noise in it. Today
     * stays in the list even once the desk has shut for the evening - "today 10:00 - 18:00"
     * read at 19:00 still tells someone the desk exists and roughly when, where dropping the
     * row silently would read as no desk at all.
     *
     * @return list<array{date: string, opens: string, closes: string, note: string|null, reminds_at: string|null}>
     */
    public static function upcoming(?Event $event): array
    {
        $today = CarbonImmutable::now()->format('Y-m-d');

        return array_values(array_filter(
            self::forEvent($event),
            fn (array $row) => $row['date'] >= $today,
        ));
    }

    /**
     * Today's row, or null when the desk publishes nothing for today.
     *
     * @return array{date: string, opens: string, closes: string, note: string|null, reminds_at: string|null}|null
     */
    public static function today(?Event $event): ?array
    {
        $today = CarbonImmutable::now()->format('Y-m-d');

        foreach (self::forEvent($event) as $row) {
            if ($row['date'] === $today) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether today is the last day the desk publishes hours for.
     *
     * Answered off the published rows rather than `events.ends_at`: the desk regularly stops
     * a day before the convention does, and the promise a mail makes ("we are open today
     * until ...") has to match the day somebody is actually standing there.
     */
    public static function isLastDay(?Event $event): bool
    {
        $rows = self::forEvent($event);

        return $rows !== [] && end($rows)['date'] === CarbonImmutable::now()->format('Y-m-d');
    }

    /**
     * The rows that may carry a reminder time: every published day except the first.
     *
     * The first day is excluded here rather than in the sender, so the editor and the schedule
     * agree by construction. Nobody is late for a badge on the day the desk opens - the whole
     * queue is collecting for the first time - and a "you have not picked it up yet" mail sent
     * into that is both wrong and the busiest possible moment to send it.
     *
     * @return list<array{date: string, opens: string, closes: string, note: string|null, reminds_at: string|null}>
     */
    public static function remindableRows(?Event $event): array
    {
        return array_slice(self::forEvent($event), 1);
    }

    /**
     * Whether this date is the first day the desk publishes, i.e. the day that never reminds.
     */
    public static function isFirstDate(?Event $event, string $date): bool
    {
        return self::firstDate($event) === $date;
    }

    /**
     * Today's reminder row when its time has come and it is still desk hours, otherwise null.
     *
     * Three conditions, and each one is a mail nobody should receive if it is missing:
     *
     *  - a time is set for today, and today is not the first published day (`remindableRows()`),
     *  - that time has passed, but by no more than `$windowMinutes`, so a scheduler that was down
     *    for an afternoon does not fire the day's mail at nine in the evening,
     *  - and the desk is still open, because the mail tells people to walk to a counter.
     *
     * The window is what makes this safe to call every minute: it opens once, and the sender's own
     * per-attendee stamp is what keeps the minutes inside it from mailing anybody twice.
     *
     * @return array{date: string, opens: string, closes: string, note: string|null, reminds_at: string|null}|null
     */
    public static function dueReminder(?Event $event, int $windowMinutes = 15): ?array
    {
        $now = CarbonImmutable::now();
        $today = $now->format('Y-m-d');

        foreach (self::remindableRows($event) as $row) {
            if ($row['date'] !== $today || $row['reminds_at'] === null) {
                continue;
            }

            $due = CarbonImmutable::createFromFormat('!Y-m-d H:i', $row['date'].' '.$row['reminds_at']);

            if ($now->lt($due) || $now->gte($due->addMinutes($windowMinutes))) {
                return null;
            }

            return $now->format('H:i') < $row['closes'] ? $row : null;
        }

        return null;
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
