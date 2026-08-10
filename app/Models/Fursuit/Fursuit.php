<?php

namespace App\Models\Fursuit;

use App\Domain\CatchEmAll\Models\UserCatch;
use App\Enum\FursuitReviewOutcomeEnum;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\FCEA\UserCatchLog;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\FursuitStatusState;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'image_uploaded_at' => 'datetime',
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
     * How long a missing render still counts as "on its way".
     *
     * GenerateFursuitWebpJob normally lands a second or two after the upload, but it can
     * also give up for good: a file GD refuses to decode is logged and never retried, and
     * a fursuit imported before the renders existed never had a job at all. Past this
     * window such a row stops counting as processing and goes back to showing its master
     * photo, so a render that will never arrive hides a submission for minutes rather
     * than forever.
     */
    public const IMAGE_RENDER_GRACE_MINUTES = 15;

    /**
     * A photo whose gallery render has not landed yet.
     *
     * The panel shows a "still processing" placeholder for these instead of pulling the
     * archival master (well over a megabyte at 1500x2000) into a preview box, and the
     * review queue skips them: a verdict is passed on the picture, and a reviewer should
     * not be handed a record whose picture is not there yet.
     *
     * The clock is `image_uploaded_at` - when the photo was stored - and never `updated_at`,
     * which is what this used to read. `updated_at` moves on every write to the row, so a
     * record whose render had failed for good went back to "still processing" the moment a
     * reviewer approved it, and dropped out of the queue for another grace window with it.
     * `created_at` covers rows written before the column existed.
     */
    public function imageRenderPending(): bool
    {
        if (! $this->image || $this->image_webp) {
            return false;
        }

        $uploadedAt = $this->image_uploaded_at ?? $this->created_at;

        return $uploadedAt === null
            || $uploadedAt->gt(now()->subMinutes(self::IMAGE_RENDER_GRACE_MINUTES));
    }

    /**
     * The same question as a where clause: rows whose render is not still in flight.
     */
    public function scopeImageRenderSettled(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNotNull('image_webp')
            ->orWhereNull('image')
            ->orWhere('image', '')
            ->orWhereRaw(
                'COALESCE(fursuits.image_uploaded_at, fursuits.created_at) <= ?',
                [now()->subMinutes(self::IMAGE_RENDER_GRACE_MINUTES)]
            ));
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

    public function reviewDecisions(): HasMany
    {
        return $this->hasMany(FursuitReviewDecision::class);
    }

    /**
     * Superseded versions of this submission, written by FursuitObserver.
     *
     * What a reviewer reads on a resubmission: an attendee who was told their image is not a
     * photo of a costume and sent the same file back looks identical to one who fixed it,
     * unless the previous version is on screen next to the current one.
     */
    public function submissionRevisions(): HasMany
    {
        return $this->hasMany(FursuitSubmissionRevision::class);
    }

    /**
     * The verdict currently standing on this fursuit, if any.
     */
    public function latestReviewDecision(): ?FursuitReviewDecision
    {
        return $this->reviewDecisions()->latest('id')->first();
    }

    /**
     * The reviewer's finding on a rejection that is still standing.
     *
     * The attendee has to change something, so the badge page shows the sentence itself rather
     * than sending them to their mail for it. Null when the rejection came from the legacy
     * transition, which logged its reason as activity and wrote no decision row.
     */
    public function rejectionReason(): ?string
    {
        if (! $this->status instanceof Rejected) {
            return null;
        }

        $decision = $this->reviewDecisions()->whereNull('undone_at')->latest('id')->first();

        return $decision?->outcome === FursuitReviewOutcomeEnum::Rejected
            ? $decision->reason
            : null;
    }

    /**
     * Whether a reviewer has barred this fursuit from the gallery and the game.
     *
     * Independent of `status`: a blocked fursuit is still approved, so its card prints
     * and is handed out. The block only covers the public surfaces, and it sits over the
     * attendee's own `published` / `catch_em_all` switches rather than overwriting them,
     * so lifting it restores what the attendee asked for.
     */
    public function isPublicationBlocked(): bool
    {
        return $this->publication_blocked_at !== null;
    }

    /**
     * Whether the gallery and Catch-Em-All may show this fursuit at all.
     *
     * The reviewer's verdict and the attendee's switch both have to say yes. Read this
     * rather than testing `published` alone.
     */
    public function isPublishable(): bool
    {
        return $this->status instanceof Approved
            && ! $this->isPublicationBlocked()
            && (bool) $this->published;
    }

    /**
     * Whether this fursuit's badges may be printed and handed out.
     *
     * Only a Code of Conduct rejection blocks the card. A publication block never reaches
     * here, and neither does a review that has not happened yet.
     *
     * Pending used to block too, which read as the safe default and was not: reviewing is
     * a queue that runs behind, printing is an attendee standing at the desk, and tying
     * the second to the first means a paid badge with nothing wrong with it cannot be
     * handed over because nobody has looked at it yet. In EF30 that was 3 461 of 3 632
     * badges. Whether an unreviewed submission should go out is a judgement for whoever is
     * at the desk with the attendee in front of them; the queue's job is to refuse the one
     * case a reviewer has actually ruled on.
     *
     * So this is a deny-list, not an allow-list, and it stays one: a state added to the
     * machine prints unless it is explicitly refused here.
     */
    public function isPrintable(): bool
    {
        return ! $this->status instanceof Rejected;
    }

    /**
     * Restrict a query to fursuits a reviewer has not barred from the public surfaces.
     *
     * Belt and braces. Blocking also turns the attendee's own `published` and
     * `catch_em_all` switches off, which is what every existing gallery and game query
     * already filters on, so this scope changes nothing on its own. It is here because the
     * attendee can turn a switch back on from their badge page: the switch is a request,
     * the block is the answer, and the answer has to win until a reviewer changes it.
     */
    public function scopePublicationAllowed(Builder $query): Builder
    {
        return $query->whereNull('publication_blocked_at');
    }

    /**
     * Drop the publication block, e.g. because the attendee resubmitted.
     *
     * Does not save: callers are usually mid-update on the same model.
     */
    public function clearPublicationBlock(): void
    {
        $this->publication_blocked_at = null;
        $this->publication_block_reason = null;
    }

    /**
     * The five-minute cache lock the old panel used to reserve a record.
     *
     * Legacy only. The Inertia panel replaced it with FursuitPresence, which is advisory:
     * it tells a reviewer that somebody else is on the record and keeps the queue from
     * handing the same record to two people, without ever refusing a decision. The lock
     * refused decisions instead, which meant a reviewer who opened a record by link could
     * do nothing with it, and a stale lock froze a record for five minutes.
     *
     * Unused since the old panel was removed: nothing calls it any more.
     */
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
