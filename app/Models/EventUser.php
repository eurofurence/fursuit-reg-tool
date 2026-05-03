<?php

namespace App\Models;

use App\Domain\CatchEmAll\Models\UserAchievement;
use App\Domain\CatchEmAll\Models\UserCatch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventUser extends Model
{
    use HasFactory;

    protected $guarded = [];

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

    public function fursuitsCatched(): HasMany
    {
        return $this->hasMany(UserCatch::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
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
