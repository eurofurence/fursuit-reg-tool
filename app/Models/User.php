<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use Bavix\Wallet\Interfaces\Customer;
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Interfaces\WalletFloat;
use Bavix\Wallet\Traits\CanPayFloat;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements Customer, FilamentUser, Wallet, WalletFloat
{
    use CanPayFloat, HasFactory, Notifiable;

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

    public function fursuitsCatched()
    {
        return $this->hasMany(\App\Domain\CatchEmAll\Models\UserCatch::class);
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

        $prepaidBadges = $eventUser->prepaid_badges;
        $orderedBadges = $this->badges()
            ->whereHas('fursuit.event', function ($query) use ($event) {
                $query->where('id', $event->id);
            })
            ->count();

        return max(0, $prepaidBadges - $orderedBadges);
    }
}
