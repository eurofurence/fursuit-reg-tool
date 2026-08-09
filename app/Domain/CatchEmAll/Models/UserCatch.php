<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\FursuitRarity;
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

    public function getFursuitRarity(): FursuitRarity
    {
        // Calculate rarity based on global frequency of fursuits
        return FursuitRarity::fromCatchCount($this->getCatches());
    }

    public function getFursuitSpecies(): string
    {
        return $this->fursuit?->species?->name ?? 'Unknown';
    }

    public function getCatches(): int
    {
        // Calculate rarity based on global frequency of fursuits
        $count = UserCatch::where('fursuit_id', $this->fursuit_id)->count();

        return $count;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['event_user_id', 'fursuit_id']);
    }
}
