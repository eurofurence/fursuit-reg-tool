<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Fursuit\Fursuit;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Gate;

class User extends Authenticatable implements FilamentUser
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
        // against the raw tinyint (rebuild-plan 2.10 change 47, audit landmine 58).
        'is_reviewer' => 'bool',
        'refresh_token' => 'encrypted',
        'refresh_token_expires_at' => 'datetime',
        'token' => 'encrypted',
        'token_expires_at' => 'datetime',
    ];

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

    public function eventUser($eventId = null)
    {
        $eventId = $eventId ?? Event::getActiveEvent()?->id;

        return $this->eventUsers()->where('event_id', $eventId)->first();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin || $this->is_reviewer;
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

        // The user's full prepaid entitlement is honored as free badges. (Historically this
        // deducted an extra 1 after order_starts_at — "the included badge is no longer honored" —
        // which wrongly charged the user's last prepaid badge. See docs/bugfix-03-fix.md.)
        $prepaidBadges = $eventUser->prepaid_badges;

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
