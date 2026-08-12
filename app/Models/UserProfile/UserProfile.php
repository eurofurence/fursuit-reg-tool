<?php

namespace App\Models\UserProfile;

use App\Models\User;
use App\Models\UserProfile\States\Approved;
use App\Models\UserProfile\States\Pending;
use App\Models\UserProfile\States\Rejected;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\ModelStates\HasStates;

class UserProfile extends Model
{
    use HasStates, LogsActivity;

    protected $guarded = [];

    /**
     * Profile colours the attendee can pick, checked against the dark surface.
     *
     * A key from this list is the only colour a profile can hold, which is why
     * changing it does not require a fresh review the way the description, the
     * links or the avatar do: a preset carries no content to moderate.
     */
    public const PALETTE = [
        'teal' => '#19b3a2',
        'green' => '#4bb161',
        'lime' => '#9db83c',
        'gold' => '#d9a520',
        'orange' => '#e0762a',
        'red' => '#d9534f',
        'pink' => '#d95d8f',
        'purple' => '#9b6ae0',
        'blue' => '#4a8fe0',
        'ice' => '#5fc3d9',
        'slate' => '#8496ad',
    ];

    /** Hex for the chosen colour, or null when it follows the fursuit. */
    public function colourHex(): ?string
    {
        return self::PALETTE[$this->colour] ?? null;
    }

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserProfile $profile) {
            $profile->uuid ??= (string) Str::uuid();
            $profile->approved_at ??= now();
        });

        static::updating(function (UserProfile $profile) {
            if ($profile->isDirty('description')) {
                $profile->approved_at = null;
                $profile->rejected_at = null;
                $profile->rejection_reason = null;
            }
        });
    }

    /**
     * Revert an approved/rejected profile back to pending because the user
     * changed its content (e.g. a link was added/edited/removed), so it needs
     * a fresh review.
     */
    public function requiresReapproval(): void
    {
        if ($this->approved_at === null && $this->rejected_at === null) {
            return;
        }

        $this->approved_at = null;
        $this->rejected_at = null;
        $this->rejection_reason = null;
        $this->save();
    }

    private function getClaimCacheKey(): string
    {
        return 'user-profile:'.$this->id.':claim';
    }

    public function claim(User $user): bool
    {
        $cacheKey = $this->getClaimCacheKey();

        if (cache()->has($cacheKey)) {
            return false;
        }

        cache()->put($cacheKey, $user->id, now()->addMinutes(5));

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

    public function isClaimed(): bool
    {
        return cache()->has($this->getClaimCacheKey());
    }

    public function isClaimedBySelf(User $user): bool
    {
        return (int) cache()->get($this->getClaimCacheKey()) === $user->id;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(UserProfileLink::class);
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: function () {
                $stateClass = match (true) {
                    $this->approved_at !== null => Approved::class,
                    $this->rejected_at !== null => Rejected::class,
                    default => Pending::class,
                };

                return (new $stateClass($this))->setField('status');
            },
        )->withoutObjectCaching();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['description', 'approved_at', 'rejected_at']);
    }
}
