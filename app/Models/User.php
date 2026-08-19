<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Fursuit\Fursuit;
use App\Models\UserProfile\UserProfile;
use App\Models\UserProfile\UserProfileLink;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'remember_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'remember_token',
        'token',
        'token_expires_at',
        'refresh_token',
        'refresh_token_expires_at',
    ];

    protected $casts = [
        'is_admin' => 'bool',
        // Uncast until now while is_admin was cast, so `is_reviewer === true` was false
        // against the raw tinyint.
        'is_reviewer' => 'bool',
        'refresh_token' => 'encrypted',
        'refresh_token_expires_at' => 'datetime',
        'token' => 'encrypted',
        'token_expires_at' => 'datetime',
    ];

    protected $appends = [
        'avatar_url',
    ];

    public function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->avatar_path) {
                    return null;
                }

                try {
                    return Storage::temporaryUrl($this->avatar_path, now()->addMinutes(5));
                } catch (\Exception $e) {
                    // Fallback
                    return Storage::url($this->avatar_path);
                }
            },
        );
    }

    public function badges()
    {
        return $this->hasManyThrough(Badge::class, Fursuit::class);
    }

    public function fursuits()
    {
        return $this->hasMany(Fursuit::class);
    }

    /**
     * Total the user still owes, in cents, positive for debt.
     *
     * Derived from badge state rather than the wallet: `status_payment` is what POS checkout
     * selects on (CheckoutController@store), and it is the record that stayed correct where the
     * wallet drifted. Note the sign flip from `balanceInt`, which was negative-for-debt.
     */
    public function amountDue(): int
    {
        return (int) $this->badges()
            ->where('badges.status_payment', Unpaid::$name)
            ->sum('badges.total');
    }

    public function eventUsers()
    {
        return $this->hasMany(EventUser::class);
    }

    public function userProfile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function userProfileLinks()
    {
        return $this->hasManyThrough(UserProfileLink::class, UserProfile::class);
    }

    public function eventUser($eventId = null)
    {
        $eventId = $eventId ?? Event::getActiveEvent()?->id;

        return $this->eventUsers()->where('event_id', $eventId)->first();
    }

    public function hasFreeBadge($eventId = null): bool
    {
        $eventUser = $this->eventUser($eventId);

        return $eventUser ? $eventUser->hasFreeBadge() : false;
    }

    public function getFreeBadgeCopiesAttribute()
    {
        $eventUser = $this->eventUser();

        return $eventUser ? $eventUser->free_badge_copies : 0;
    }

    public function getPrepaidBadgesLeft($eventId = null): int
    {
        $eventUser = $this->eventUser($eventId);
        $event = Event::getActiveEvent();

        if (! $eventUser || ! $event) {
            return 0;
        }

        // Check if Badges can be created (e.g. order deadline is over)
        if (! Gate::allows('create', Badge::class)) {
            return 0;
        }

        // `prepaid_badges` is the sum of two different things (AuthController sets it from
        // the registration system): the one badge that comes with the fursuit package, and
        // however many extra copies were bought on top of it as `fursuitadd`.
        //
        // Only the first of those expires. The deadline the Welcome page and the FAQ quote
        // is exactly this: submit by then and the included badge is free, order later and it
        // costs the badge fee like any other. Extra copies were paid for in the registration
        // system and stay free whenever they are claimed.
        //
        // This is not the `- 1` that was removed as bugfix-03. That one keyed off
        // `order_starts_at`, which is the moment general ordering *opens*, so it charged the
        // included badge for the entire window it was supposed to be free in. This keys off
        // `free_badge_deadline`, the column that exists for the question and until now was
        // only ever rendered, never enforced. A null deadline means the event never set one,
        // and the entitlement is honored in full. See docs/prepaid-badges.md.
        $prepaidBadges = $eventUser->prepaid_badges;

        if ($event->free_badge_deadline && $event->free_badge_deadline < now()) {
            $prepaidBadges = max(0, $prepaidBadges - 1);
        }

        // Only main badges consume the prepaid allowance; spare copies are always separately
        // paid (extra_copy_of !== null) and must not eat into the free entitlement.
        $orderedBadges = $this->badges()
            ->whereHas('fursuit.event', function ($query) use ($event) {
                $query->where('id', $event->id);
            })
            ->whereNull('extra_copy_of')
            ->count();

        return max(0, $prepaidBadges - $orderedBadges);
    }
}
