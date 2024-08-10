<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Machine describes a pos system
 */

class Machine extends Authenticatable
{
    public $timestamps = false;
    protected $guarded = [];
}
