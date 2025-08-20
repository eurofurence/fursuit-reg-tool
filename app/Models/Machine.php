<?php

namespace App\Models;

use App\Domain\Printing\Models\Printer;
use Bavix\Wallet\Traits\HasWalletFloat;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * Machine describes a pos system
 */
class Machine extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable, Authorizable, HasWalletFloat;

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

    // checkouts
    public function checkouts()
    {
        return $this->hasMany(\App\Domain\Checkout\Models\Checkout\Checkout::class);
    }

    // tse client
    public function tseClient()
    {
        return $this->belongsTo(\App\Domain\Checkout\Models\TseClient::class);
    }

    // sumupReader
    public function sumupReader()
    {
        return $this->belongsTo(SumUpReader::class);
    }
}
