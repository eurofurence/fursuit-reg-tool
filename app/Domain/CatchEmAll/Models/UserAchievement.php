<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\AchievementOLD;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAchievement extends Model
{
    protected $fillable = [
        'user_id',
        'achievement',
        'earned_at',
        'progress',
        'max_progress',
    ];

    protected $casts = [
        'achievement' => AchievementOLD::class,
        'earned_at' => 'datetime',
        'progress' => 'integer',
        'max_progress' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->earned_at !== null;
    }

    public function getProgressPercentage(): int
    {
        if ($this->max_progress === 0) {
            return 100;
        }

        return min(100, (int) round(($this->progress / $this->max_progress) * 100));
    }
}
