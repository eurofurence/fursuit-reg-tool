<?php

namespace App\Models;

use App\Enum\EventStateEnum;
use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'badge_class',
        'starts_at',
        'ends_at',
        'order_starts_at',
        'order_ends_at',
        'free_badge_deadline',
        'badge_price_cents',
        'mass_printed_at',
        'pickup_booths',
        'desk_opening_hours',
        'catch_em_all_enabled',
        'catch_em_all_start',
        'catch_em_all_end',
    ];

    protected $hidden = [
        // The financial tracking that read this is gone and nothing writes it any more,
        // but the column is still on the table, so it stays hidden rather than leaking
        // into an API payload the day someone serialises an event.
        'cost',
    ];

    protected $appends = ['state'];

    protected function casts()
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'order_starts_at' => 'datetime',
            'order_ends_at' => 'datetime',
            'free_badge_deadline' => 'datetime',
            'badge_price_cents' => 'integer',
            'mass_printed_at' => 'datetime',
            'pickup_booths' => 'array',
            'desk_opening_hours' => 'array',
            'catch_em_all_enabled' => 'boolean',
            'catch_em_all_start' => 'datetime',
            'catch_em_all_end' => 'datetime',
        ];
    }

    public static function getActiveEvent(): ?Event
    {
        return self::latest('starts_at')
            ->first();
    }

    public function state(): Attribute
    {
        return new Attribute(get: function () {
            // If event end date has passed, then closed
            if ($this->ends_at < now()) {
                return EventStateEnum::CLOSED;
            }
            // If orders haven't started yet, then closed
            if ($this->order_starts_at && $this->order_starts_at > now()) {
                return EventStateEnum::CLOSED;
            }
            // If order period has ended, then closed
            if ($this->order_ends_at && $this->order_ends_at < now()) {
                return EventStateEnum::CLOSED;
            }

            // Orders are allowed (event may not have started yet, but orders are open)
            return EventStateEnum::OPEN;
        });
    }

    public function allowsOrders(): bool
    {
        return $this->state === EventStateEnum::OPEN;
    }

    public function isInOrderWindow(): bool
    {
        $now = now();
        $orderStarted = ! $this->order_starts_at || $this->order_starts_at <= $now;
        $orderNotEnded = ! $this->order_ends_at || $this->order_ends_at > $now;

        return $orderStarted && $orderNotEnded;
    }

    public function isDuringEvent(): bool
    {
        $now = now();

        return $this->starts_at <= $now && $this->ends_at >= $now;
    }

    public function fursuits()
    {
        return $this->hasMany(Fursuit::class);
    }

    public function badges()
    {
        return $this->hasManyThrough(Badge::class, Fursuit::class);
    }

    public function eventUsers()
    {
        return $this->hasMany(EventUser::class);
    }

    public function getTotalRevenueAttribute(): float
    {
        return $this->badges()->sum('total') / 100; // Convert cents to euros
    }

    public function isCatchEmAllActive(): bool
    {
        $now = now();
        $catchEmAllStarted = $this->catch_em_all_start ? $this->catch_em_all_start <= $now : $this->starts_at <= $now;
        $catchEmAllNotEnded = $this->catch_em_all_end ? $this->catch_em_all_end > $now : $this->ends_at >= $now;

        return $this->catch_em_all_enabled && $catchEmAllStarted && $catchEmAllNotEnded;
    }
}
