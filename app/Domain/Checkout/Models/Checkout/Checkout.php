<?php

namespace App\Domain\Checkout\Models\Checkout;

use App\Domain\Checkout\Models\Checkout\States\CheckoutStatusState;
use App\Domain\Printing\Models\PrintJob;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\ModelStates\HasStates;

class Checkout extends Model
{
    use HasStates;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fiskaly_data' => 'array',
            'remote_rev_count' => 'integer',
            'status' => CheckoutStatusState::class,
            'tse_timestamp' => 'datetime',
            'tse_start_timestamp' => 'datetime',
            'tse_end_timestamp' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items()
    {
        return $this->hasMany(CheckoutItem::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function printJobs()
    {
        return $this->morphMany(PrintJob::class, 'printable');
    }
}
