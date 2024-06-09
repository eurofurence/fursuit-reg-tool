<?php

namespace App\Models;

use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Eloquent\Model;

class Species extends Model
{
    protected $guarded = [];

    public function fursuits()
    {
        return $this->hasMany(Fursuit::class);
    }
}
