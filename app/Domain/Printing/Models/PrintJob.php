<?php

namespace App\Domain\Printing\Models;

use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PrintJob extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory()
    {
        return \Database\Factories\PrintJobFactory::new();
    }

    protected $casts = [
        'printed_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'failed_at' => 'datetime',
        'type' => PrintJobTypeEnum::class,
        'status' => PrintJobStatusEnum::class,
        'retry_count' => 'integer',
        'priority' => 'integer',
    ];

    public function printable()
    {
        return $this->morphTo();
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function processingMachine()
    {
        return $this->belongsTo(Machine::class, 'processing_machine_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', PrintJobStatusEnum::Pending);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            PrintJobStatusEnum::Queued,
            PrintJobStatusEnum::Printing,
            PrintJobStatusEnum::Retrying,
        ]);
    }

    public function scopePrioritized($query)
    {
        return $query->orderBy('priority', 'desc')
                     ->orderBy('created_at', 'asc');
    }

    // State transition methods
    public function transitionTo(PrintJobStatusEnum $newStatus, ?string $errorMessage = null): bool
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            return false;
        }

        return DB::transaction(function () use ($newStatus, $errorMessage) {
            $updates = ['status' => $newStatus];

            switch ($newStatus) {
                case PrintJobStatusEnum::Queued:
                    $updates['queued_at'] = now();
                    break;
                case PrintJobStatusEnum::Printing:
                    $updates['started_at'] = now();
                    break;
                case PrintJobStatusEnum::Printed:
                    $updates['printed_at'] = now();
                    break;
                case PrintJobStatusEnum::Failed:
                    $updates['failed_at'] = now();
                    $updates['error_message'] = $errorMessage;
                    break;
                case PrintJobStatusEnum::Retrying:
                    $updates['retry_count'] = $this->retry_count + 1;
                    break;
            }

            return $this->update($updates);
        });
    }

    public function assignToMachine(Machine $machine): void
    {
        $this->update(['processing_machine_id' => $machine->id]);
    }

    public function canRetry(): bool
    {
        return $this->status === PrintJobStatusEnum::Failed &&
               $this->retry_count < 3;
    }

    public function shouldRetry(): bool
    {
        return $this->canRetry() &&
               $this->failed_at?->lt(now()->subMinutes(5));
    }
}
