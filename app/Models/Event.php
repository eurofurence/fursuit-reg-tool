<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts()
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'preorder_ends_at' => 'datetime',
        ];
    }

    public static function getActiveEvent(): Event|null
    {
        return self::where('ends_at', '>', now())->orderBy('starts_at')->first();
    }
}
