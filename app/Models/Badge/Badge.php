<?php

namespace App\Models\Badge;

use App\Domain\Printing\Models\PrintJob;
use App\Models\Badge\State_Fulfillment\BadgeFulfillmentStatusState;
use App\Models\Badge\State_Payment\BadgePaymentStatusState;
use App\Models\Fursuit\Fursuit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\ModelStates\HasStates;

class Badge extends Model
{
    use HasFactory, HasStates, LogsActivity, SoftDeletes;

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
        'print_file_generated_at' => 'datetime',
        'verified_print_at' => 'datetime',
        'printing_locked_at' => 'datetime',
    ];

    /**
     * Whether this badge has been committed to a print batch.
     *
     * Batches are immutable and their artwork is rendered up front, so once a
     * badge is in one the attendee can no longer change it. Otherwise the card
     * in the stack would stop matching the order.
     */
    public function isPrintingLocked(): bool
    {
        return $this->printing_locked_at !== null;
    }

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function printJobs()
    {
        return $this->morphMany(PrintJob::class, 'printable');
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
