<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Checkout\Enums\TseClientStateEnum;
use Illuminate\Database\Eloquent\Model;

class TseClient extends Model
{
    protected $guarded = [];

    protected $casts = [
        'state' => TseClientStateEnum::class,
    ];

    public function machine()
    {
        return $this->hasOne(\App\Models\Machine::class);
    }
}
