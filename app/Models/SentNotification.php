<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One notification we actually delivered.
 *
 * A log, not a queue: rows are written after the framework reports a send and are never used to
 * decide whether to send. The pickup reminder keeps its own `badges.pickup_reminded_at` stamp for
 * that, because a log that doubles as a guard turns "we failed to write the log" into "we mail
 * everybody again".
 */
class SentNotification extends Model
{
    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'notification',
        'channel',
        'subject',
        'subject_model_type',
        'subject_model_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The record the notification was about, when it carried one. */
    public function subjectModel(): MorphTo
    {
        return $this->morphTo('subject_model');
    }

    /**
     * The notification's class turned into words, e.g. "Badge Pickup Reminder".
     *
     * Derived rather than stored: the class is the fact, and a label stored beside it would be a
     * second copy of the same thing that stops matching the day a notification is renamed.
     */
    public function label(): string
    {
        $base = class_basename($this->notification);
        $base = preg_replace('/Notification$/', '', $base) ?? $base;

        return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $base) ?? $base);
    }
}
