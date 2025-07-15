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
            'preorder_starts_at' => 'datetime',
            'preorder_ends_at' => 'datetime',
            'order_ends_at' => 'datetime',
            'mass_printed_at' => 'datetime',
        ];
    }

    public static function getActiveEvent(): Event|null
    {
        return self::where('ends_at', '>', now())->orderBy('starts_at')->first();
    }

    public function state(): Attribute
    {
        return new Attribute(get: function ($value) {
            // If Preorder is in the future, then countdown
            if ($this->preorder_starts_at > now()) {
                return EventStateEnum::COUNTDOWN;
            }
            // If Date is between Preorder dates, then preorder
            if ($this->preorder_starts_at < now() && $this->preorder_ends_at > now()) {
                return EventStateEnum::PREORDER;
            }
            // If Date is between Preorder and Order dates, then late
            if ($this->preorder_ends_at < now() && $this->order_ends_at > now()) {
                return EventStateEnum::LATE;
            }
            // If Date is after Order date, then closed
            return EventStateEnum::CLOSED;
        });
    }
}
