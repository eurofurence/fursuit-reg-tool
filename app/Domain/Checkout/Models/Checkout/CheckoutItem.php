<?php

namespace App\Domain\Checkout\Models\Checkout;

use Illuminate\Database\Eloquent\Model;

class CheckoutItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'description' => 'array',
        ];
    }

    public function checkout()
    {
        return $this->belongsTo(Checkout::class);
    }

    public function payable()
    {
        return $this->morphTo();
    }
}
