<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * Machine describes a pos system
 */

class Machine extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable, Authorizable;
    public $timestamps = false;
    protected $guarded = [];
}
