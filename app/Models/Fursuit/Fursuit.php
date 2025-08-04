<?php

namespace App\Models\Fursuit;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\FCEA\UserCatch;
use App\Models\FCEA\UserCatchLog;
use App\Models\Fursuit\States\FursuitStatusState;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\ModelStates\HasStates;

class Fursuit extends Model
{
    use HasFactory, HasStates, LogsActivity, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'status' => FursuitStatusState::class,
        'published' => 'boolean',
        'catch_em_all' => 'boolean',
    ];

    protected $appends = ['image_url', 'image_webp_url'];

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

    public function imageWebpUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                // If webp version doesn't exist, try to generate it
                if (! $this->image_webp && $this->image) {
                    try {
                        $originalImage = Storage::get($this->image);
                        $manager = new ImageManager(new Driver);
                        $path = 'gallery/fursuits/'.pathinfo($this->image, PATHINFO_FILENAME).'.webp';

                        $webp = $manager->read($originalImage)->toWebp();
                        Storage::put($path, $webp);
                        $this->update(['image_webp' => $path]);

                        return Storage::temporaryUrl($path, now()->addMinutes(5));
                    } catch (\Exception $e) {
                        // Log the error for debugging
                        \Log::warning('Failed to generate WebP for fursuit '.$this->id.': '.$e->getMessage());

                        // Fallback to original image if WebP generation fails
                        return Storage::temporaryUrl($this->image, now()->addMinutes(5));
                    }
                }

                // Return existing webp image if available
                if ($this->image_webp) {
                    try {
                        return Storage::temporaryUrl($this->image_webp, now()->addMinutes(5));
                    } catch (\Exception $e) {
                        // If webp URL generation fails, fall back to original
                        \Log::warning('Failed to generate WebP URL for fursuit '.$this->id.': '.$e->getMessage());
                    }
                }

                // Fallback to original image
                if ($this->image) {
                    try {
                        return Storage::temporaryUrl($this->image, now()->addMinutes(5));
                    } catch (\Exception $e) {
                        \Log::error('Failed to generate image URL for fursuit '.$this->id.': '.$e->getMessage());

                        return null;
                    }
                }

                return null;
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

        if (! cache()->has($cacheKey)) {
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
        return ! cache()->has($this->getClaimCacheKey());
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

    /**
     * Clear catch code cache when fursuit is updated
     */
    protected static function boot()
    {
        parent::boot();

        static::updating(function (Fursuit $fursuit) {
            // Clear catch code cache if it's changing
            if ($fursuit->isDirty('catch_code') && $fursuit->getOriginal('catch_code')) {
                UserCatchLog::clearFursuitCache($fursuit->getOriginal('catch_code'));
            }

            // Clear total fursuiters cache if catch_em_all flag changes
            if ($fursuit->isDirty('catch_em_all') && $fursuit->event_id) {
                Cache::forget("total_fursuiters_{$fursuit->event_id}");
            }
        });

        static::updated(function (Fursuit $fursuit) {
            // Clear cache for new catch code after update
            if ($fursuit->wasChanged('catch_code') && $fursuit->catch_code) {
                UserCatchLog::clearFursuitCache($fursuit->catch_code);
            }
        });

        static::deleted(function (Fursuit $fursuit) {
            // Clear catch code cache when fursuit is deleted
            if ($fursuit->catch_code) {
                UserCatchLog::clearFursuitCache($fursuit->catch_code);
            }

            // Clear total fursuiters cache
            if ($fursuit->event_id) {
                Cache::forget("total_fursuiters_{$fursuit->event_id}");
            }
        });
    }
}
