<?php

namespace App\Models\Fursuit;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\FCEA\UserCatch;
use App\Models\Fursuit\States\FursuitStatusState;
use App\Models\Species;
use App\Models\User;
use App\Services\FursuitCatchCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\ModelStates\HasStates;

class Fursuit extends Model
{
    use HasStates, LogsActivity, HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'status' => FursuitStatusState::class,
        'published' => 'boolean',
        'catch_em_all' => 'boolean',
    ];

    protected $appends = ['image_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function badges()
    {
        return $this->hasMany(Badge::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function imageUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return Storage::temporaryUrl($this->image, now()->addMinutes(5));
            },
        );
    }

    private function getClaimCacheKey(): string
    {
        return 'fursuit:'.$this->id.':claim';
    }

    public function claim(User $user): bool
    {
        $cacheKey = $this->getClaimCacheKey();

        if (cache()->has($cacheKey)) {
            return false;
        }

        cache()->put($cacheKey, auth()->user()->id, now()->addMinutes(5));

        return true;
    }

    public function unclaim(): bool
    {
        $cacheKey = $this->getClaimCacheKey();

        if (!cache()->has($cacheKey)) {
            return false;
        }

        cache()->forget($cacheKey);

        return true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'image', 'species_id']);
        // Chain fluent methods for configuration options
    }

    public function isNotClaimed(): bool
    {
        return !cache()->has($this->getClaimCacheKey());
    }

    public function isClaimed()
    {
        return cache()->has($this->getClaimCacheKey());
    }

    public function isClaimedBySelf(User $user)
    {
        return (int) cache()->get($this->getClaimCacheKey()) == $user->id;
    }

    public function catchedByUsers()
    {
        return $this->hasMany(UserCatch::class);
    }
}
