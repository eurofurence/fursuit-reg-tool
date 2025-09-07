<?php

namespace App\Models;

use App\Enum\EventStateEnum;
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
        'mass_printed_at',
        'catch_em_all_enabled',
        'catch_em_all_start',
        'catch_em_all_end',
        'cost',
    ];

    protected $hidden = [
        'cost', // Never expose printing costs to public API
    ];

    protected $appends = ['state'];

    protected function casts()
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'order_starts_at' => 'datetime',
            'order_ends_at' => 'datetime',
            'mass_printed_at' => 'datetime',
            'catch_em_all_enabled' => 'boolean',
            'catch_em_all_start' => 'datetime',
            'catch_em_all_end' => 'datetime',
            'cost' => 'decimal:2',
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
        $orderStarted = !$this->order_starts_at || $this->order_starts_at <= $now;
        $orderNotEnded = !$this->order_ends_at || $this->order_ends_at > $now;

        return $orderStarted && $orderNotEnded;
    }

    public function isDuringEvent(): bool
    {
        $now = now();

        return $this->starts_at <= $now && $this->ends_at >= $now;
    }

    public function fursuits()
    {
        return $this->hasMany(\App\Models\Fursuit\Fursuit::class);
    }

    public function badges()
    {
        return $this->hasManyThrough(\App\Models\Badge\Badge::class, \App\Models\Fursuit\Fursuit::class);
    }

    public function eventUsers()
    {
        return $this->hasMany(EventUser::class);
    }

    public function getTotalRevenueAttribute(): float
    {
        return $this->badges()->sum('total') / 100; // Convert cents to euros
    }

    public function getPaidBadgeRevenueAttribute(): float
    {
        return $this->badges()
            ->where('is_free_badge', false)
            ->where('status_payment', 'paid')
            ->sum('total') / 100; // Convert cents to euros
    }

    public function getTotalPrepaidBadgeRevenueAttribute(): float
    {
        // Calculate revenue from prepaid badges beyond the free one
        $totalRevenue = 0;
        $eventUsers = $this->eventUsers()->where('prepaid_badges', '>', 1)->get();

        foreach ($eventUsers as $eventUser) {
            // Each prepaid badge beyond 1 costs €2.00
            $paidBadges = $eventUser->prepaid_badges - 1;
            $totalRevenue += $paidBadges * 2.00;
        }

        return $totalRevenue;
    }

    public function getLateBadgeRevenueAttribute(): float
    {
        return $this->badges()
            ->where('apply_late_fee', true)
            ->where('status_payment', 'paid')
            ->count() * 3.00; // Late badges cost €3.00
    }

    public function getProfitMarginAttribute(): ?float
    {
        if (!$this->cost) {
            return null;
        }

        $revenue = $this->total_revenue;

        return $revenue - $this->cost;
    }

    public function isProfitableAttribute(): ?bool
    {
        if (!$this->cost) {
            return null;
        }

        return $this->profit_margin >= 0;
    }

    public function isCatchEmAllActive(): bool
    {
        $now = now();
        $catchEmAllStarted = !$this->catch_em_all_start || $this->catch_em_all_start <= $now;
        $catchEmAllNotEnded = !$this->catch_em_all_end || $this->catch_em_all_end >= $now;

        return $this->catch_em_all_enabled && $catchEmAllStarted && $catchEmAllNotEnded;
    }
}
