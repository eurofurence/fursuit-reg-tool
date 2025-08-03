<?php

namespace App\Models\Badge;

use App\Domain\Printing\Models\PrintJob;
use App\Models\Badge\State_Fulfillment\BadgeFulfillmentStatusState;
use App\Models\Badge\State_Payment\BadgePaymentStatusState;
use App\Models\Fursuit\Fursuit;
use Bavix\Wallet\Interfaces\Customer;
use Bavix\Wallet\Interfaces\ProductInterface;
use Bavix\Wallet\Traits\HasWalletFloat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\ModelStates\HasStates;

class Badge extends Model implements ProductInterface
{
    use HasFactory, HasStates, HasWalletFloat, LogsActivity, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'status_fulfillment' => BadgeFulfillmentStatusState::class,
        'status_payment' => BadgePaymentStatusState::class,
        'extra_copy' => 'boolean',
        'dual_side_print' => 'boolean',
        'apply_late_fee' => 'boolean',
        'printed_at' => 'datetime',
        'ready_for_pickup_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'is_free_badge' => 'boolean',
    ];

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function printJobs()
    {
        return $this->morphMany(PrintJob::class, 'printable');
    }

    public function getAmountProduct(Customer $customer): int|string
    {
        return $this->total;
    }

    public function getMetaProduct(): ?array
    {
        // Title Generator
        $features = [];
        if ($this->dual_side_print) {
            $features[] = 'Double Sided Print';
        }
        if ($this->extra_copy_of) {
            $features[] = 'Extra Copy';
        }
        $append = '';
        if (count($features) > 0) {
            $append = ' with Extras ('.implode(', ', $features).')';
        }

        return [
            'title' => 'Fursuit Badge',
            'description' => 'Purchase of Fursuit Badge #'.$this->id.$append,
        ];
    }

    protected function casts()
    {
        return [
            'picked_up_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['*']);
    }

    public function isCopyOfFreeBadge(): bool
    {
        if ($this->extra_copy_of !== null) {
            $originalBadge = self::find($this->extra_copy_of);

            return $originalBadge ? $originalBadge->is_free_badge : false;
        }

        return false;
    }
}
