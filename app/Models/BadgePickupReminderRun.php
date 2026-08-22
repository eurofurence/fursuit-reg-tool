<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day's pickup reminder send, claimed before it happens.
 *
 * Written by whichever of the two callers gets there first - the scheduler at the desk's own
 * "Remind at" time, or an operator pressing the button on the badge list - and read by the other
 * one to know the day is spoken for. See BadgePickupReminderService::claimToday().
 */
class BadgePickupReminderRun extends Model
{
    /** The scheduler, at the time the desk set on its opening hours. */
    public const SOURCE_SCHEDULE = 'schedule';

    /** Somebody pressing the button on the badge list. */
    public const SOURCE_MANUAL = 'manual';

    /** The artisan command, run by hand. */
    public const SOURCE_CONSOLE = 'console';

    protected $fillable = [
        'event_id',
        'ran_on',
        'source',
        'triggered_by',
        'attendees_notified',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'ran_on' => 'date',
            'ran_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * How this run reads in a toast: who set it off, and when.
     */
    public function describe(): string
    {
        $at = $this->ran_at?->format('H:i') ?? 'earlier';

        return match ($this->source) {
            self::SOURCE_SCHEDULE => 'The schedule already sent today\'s reminders at '.$at.'.',
            self::SOURCE_CONSOLE => 'Today\'s reminders were already sent from the console at '.$at.'.',
            default => 'Today\'s reminders were already sent at '.$at
                .($this->triggeredBy?->name ? ' by '.$this->triggeredBy->name : '').'.',
        };
    }
}
