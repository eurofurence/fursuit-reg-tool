<?php

namespace App\Domain\CatchEmAll\Models;

use App\Models\EventUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAchievement extends Model
{
    protected $fillable = [
        'event_user_id',
        'achievement',
        'earned_at',
        'progress',
        'max_progress',
    ];

    protected $casts = [
        'achievement' => 'string',
        'earned_at' => 'datetime',
        'progress' => 'integer',
        'max_progress' => 'integer',
    ];

    public function eventUser(): BelongsTo
    {
        return $this->belongsTo(EventUser::class);
    }

    public function isCompleted(): bool
    {
        return $this->earned_at !== null;
    }
}
