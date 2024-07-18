<?php

namespace App\Models;

use App\Enum\EventStateEnum;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $appends = ['state'];

    protected function casts()
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'preorder_starts_at' => 'datetime',
            'preorder_ends_at' => 'datetime',
            'order_ends_at' => 'datetime',
        ];
    }

    public static function getActiveEvent(): Event|null
    {
        return self::where('ends_at', '>', now())->orderBy('starts_at')->first();
    }

    public function state(): Attribute
    {
        return new Attribute(get: function ($value) {
            if ($this->ends_at < now()) {
                return EventStateEnum::CLOSED;
            }

            if ($this->preorder_starts_at < now() && $this->preorder_ends_at > now()) {
                return EventStateEnum::PREORDER;
            }

            if ($this->order_ends_at > now()) {
                return EventStateEnum::LATE;
            }

            return EventStateEnum::COUNTDOWN;
        });
    }
}
