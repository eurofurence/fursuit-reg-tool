<?php

namespace App\Models;

use App\Domain\Printing\Models\Printer;
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

    // badge printer
    public function badgePrinter()
    {
        return $this->belongsTo(Printer::class, 'badge_printer_id');
    }

    // receipt printer
    public function receiptPrinter()
    {
        return $this->belongsTo(Printer::class, 'receipt_printer_id');
    }

    // generic printers
    public function printers()
    {
        return $this->hasMany(Printer::class);
    }
}
