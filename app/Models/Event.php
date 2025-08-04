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

    protected $guarded = [];

    protected $appends = ['state'];

    protected function casts()
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'order_starts_at' => 'datetime',
            'order_ends_at' => 'datetime',
            'mass_printed_at' => 'datetime',
        ];
    }

    public static function getActiveEvent(): ?Event
    {
        $now = now();

        return self::latest('starts_at')
            ->get()
            ->first(function ($event) {
                return $event->allowsOrders();
            });
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

    public function fursuits()
    {
        return $this->hasMany(\App\Models\Fursuit\Fursuit::class);
    }
}
