<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\FursuitRarity;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class UserCatch extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'fursuit_id',
        'event_id',
    ];

    protected $casts = [
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Event::class);
    }

    public function getFursuitRarity(): FursuitRarity
    {
        // Calculate rarity based on global frequency of fursuits
        $count = $this->getCatches();

        $rarity = match (true) {
            $count >= config('fcea.species_rarity_threshold_legendary') => FursuitRarity::LEGENDARY,
            $count >= config('fcea.species_rarity_threshold_epic') => FursuitRarity::EPIC,
            $count >= config('fcea.species_rarity_threshold_rare') => FursuitRarity::RARE,
            $count >= config('fcea.species_rarity_threshold_uncommon') => FursuitRarity::UNCOMMON,
            default => FursuitRarity::COMMON,
        };

        return $rarity;
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
            ->logOnly(['user_id', 'fursuit_id']);
    }
}
