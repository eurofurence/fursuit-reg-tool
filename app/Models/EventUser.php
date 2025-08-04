<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventUser extends Model
{
    protected $fillable = [
        'user_id',
        'event_id',
        'attendee_id',
        'valid_registration',
        'prepaid_badges',
    ];

    protected $casts = [
        'valid_registration' => 'boolean',
        'prepaid_badges' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function hasFreeBadge(): bool
    {
        return $this->prepaid_badges > 0;
    }

    public function getFreeBadgeCopiesAttribute(): int
    {
        return max(0, $this->prepaid_badges - 1);
    }
}
