<?php

namespace App\Domain\CatchEmAll\Models;

use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class UserCatch extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'event_user_id',
        'fursuit_id',
    ];

    protected $casts = [
    ];

    public function event_user(): BelongsTo
    {
        return $this->belongsTo(EventUser::class);
    }

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function getFursuitSpecies(): string
    {
        return $this->fursuit?->species?->name ?? 'Unknown';
    }

    /**
     * How many players have caught this fursuit.
     *
     * This used to decide rarity, which made rarity a measure of fame and cost a
     * query per catch. Ranking now comes from FursuitRankingService; this is only
     * the "caught N times" figure on a profile.
     */
    public function getCatches(): int
    {
        return UserCatch::where('fursuit_id', $this->fursuit_id)->count();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['event_user_id', 'fursuit_id']);
    }
}
