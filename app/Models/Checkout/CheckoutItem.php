<?php

namespace App\Models\Checkout;

use Illuminate\Database\Eloquent\Model;

class CheckoutItem extends Model
{
    protected $guarded = [];

    public function checkout()
    {
        return $this->belongsTo(Checkout::class);
    }
}
