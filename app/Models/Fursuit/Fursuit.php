<?php

namespace App\Models\Fursuit;

use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Badge\Badge;
use App\Models\Event;
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
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\ModelStates\HasStates;

class Fursuit extends Model
{
    use HasFactory, HasStates, LogsActivity, SoftDeletes;

    /**
     * Guard flag set while a fursuit is cascading its own deletion to its badges, so the badge
     * delete events don't try to (re-)delete the fursuit. See FursuitObserver / BadgeObserver.
     */
    public static bool $isCascadingDelete = false;

    protected $guarded = [];

    protected $casts = [
        'status' => FursuitStatusState::class,
        'published' => 'boolean',
        'catch_em_all' => 'boolean',
        'publication_blocked_at' => 'datetime',
    ];

    protected $appends = ['image_url', 'image_webp_url', 'image_thumb_url'];

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
            get: fn () => self::signedStorageUrl($this->image),
        );
    }

    /**
     * The gallery variant, or the original when no webp has been rendered yet.
     *
     * Rendering is the write side's job (FursuitObserver -> GenerateFursuitWebpJob): this
     * accessor used to encode a missing webp inline, which put an s3 download plus a GD
     * encode plus a model write into every gallery request that happened to hit a fursuit
     * without one - and, because it keyed off "column empty" rather than "photo changed",
     * it served the old picture forever once the attendee replaced their photo.
     */
    public function imageWebpUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => self::variantUrl($this->image_webp),
        );
    }

    /**
     * The grid thumbnail, or the bigger variant while only that one is rendered.
     *
     * Never the master. The originals are print-quality - 2040x2720 and well over a
     * megabyte is normal - so falling back to one put a full-size photo inside a 375px
     * card. A fursuit without variants is simply not shown; the gallery query filters
     * those rows out rather than serving something that heavy.
     */
    public function imageThumbUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => self::variantUrl($this->image_thumb) ?? self::variantUrl($this->image_webp),
        );
    }

    /**
     * A link to a rendered gallery variant.
     *
     * With `gallery.public_variants` on, this is a plain unsigned URL: stable forever,
     * so the browser and any CDN in front of the bucket can actually keep the file.
     * That is the whole point - a presigned URL is a new string on every mint, which
     * turns the same picture into a cache miss on every page load.
     *
     * The switch only applies to the variant prefix. A fursuit whose render has not
     * landed yet falls back to its master photo, and that stays signed whatever the
     * setting says.
     */
    public static function variantUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (config('gallery.public_variants') && str_starts_with($path, config('gallery.variant_prefix'))) {
            return Storage::url($path);
        }

        return self::signedStorageUrl($path);
    }

    /** How long a handed-out signature stays valid. */
    public const SIGNED_URL_LIFETIME_DAYS = 7;

    /** How long we keep reusing the same signature. Shorter, so a served link never expires. */
    public const SIGNED_URL_CACHE_DAYS = 6;

    /**
     * A signed link to a private object, reusing one signature for days.
     *
     * The bucket refuses public objects, so every image is a presigned URL - and a
     * presigned URL is a *different URL* each time it is minted. That made the browser
     * cache useless: same picture, new cache key on every page load, so a gallery page
     * re-downloaded every thumbnail it had already seen. Handing out the identical
     * string for days lets `Cache-Control` on the object (see GenerateFursuitWebpJob)
     * actually do its job.
     *
     * The cache window is deliberately shorter than the signature, so nobody is ever
     * handed a link that is about to expire. Signing is also not free and the gallery
     * asks for twenty per page.
     */
    public static function signedStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Cache::remember('storage:signed-url:'.md5($path), now()->addDays(self::SIGNED_URL_CACHE_DAYS), function () use ($path) {
            try {
                return Storage::temporaryUrl($path, now()->addDays(self::SIGNED_URL_LIFETIME_DAYS));
            } catch (\Throwable $e) {
                // Disks that cannot sign (the fake used in tests, a local dev disk).
                try {
                    return Storage::url($path);
                } catch (\Throwable $e2) {
                    return null;
                }
            }
        });
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
