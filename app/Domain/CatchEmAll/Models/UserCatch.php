<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpeciesRarity;
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
        'points_earned',
    ];

    protected $casts = [
        'points_earned' => 'integer',
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

    public function getSpeciesRarity(): SpeciesRarity
    {
        if (!$this->fursuit || !$this->fursuit->species) {
            return SpeciesRarity::COMMON;
        }

        // Calculate rarity based on global frequency of species
        $speciesCount = Fursuit::whereHas('species', function ($query) {
            $query->where('id', $this->fursuit->species->id);
        })->count();

        return match (true) {
            $speciesCount >= config('fcea.species_rarity_threshold_common') => SpeciesRarity::COMMON,
            $speciesCount >= config('fcea.species_rarity_threshold_uncommon') => SpeciesRarity::UNCOMMON,
            $speciesCount >= config('fcea.species_rarity_threshold_rare') => SpeciesRarity::RARE,
            $speciesCount >= config('fcea.species_rarity_threshold_epic') => SpeciesRarity::EPIC,
            default => SpeciesRarity::LEGENDARY,
        };
    }

    public function calculatePoints(): int
    {
        return $this->getSpeciesRarity()->getPoints();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'fursuit_id', 'points_earned']);
    }
}
