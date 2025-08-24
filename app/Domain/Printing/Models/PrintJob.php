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

    protected $fillable = [
        'printer_id', 'printable_type', 'printable_id', 'type', 'status', 'file',
        'priority', 'retry_count', 'retry_of', 'processing_machine_id',
        'qz_job_name', 'last_qz_status', 'last_qz_message', 'error_message',
        'printed_at', 'queued_at', 'started_at', 'failed_at',
    ];

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

    public function originalJob()
    {
        return $this->belongsTo(self::class, 'retry_of');
    }

    public function retryJobs()
    {
        return $this->hasMany(self::class, 'retry_of');
    }

    /**
     * Create a retry job from this failed job
     */
    public function createRetryJob(bool $reassignPrinter = false): self
    {
        $printerId = $this->printer_id;

        // If reassigning, find an available printer of the same type
        if ($reassignPrinter) {
            $printerId = $this->findAvailablePrinter();
        }

        // Create a new job with the same data but referencing this as the original
        $retryJob = self::create([
            'printer_id' => $printerId,
            'printable_type' => $this->printable_type,
            'printable_id' => $this->printable_id,
            'type' => $this->type,
            'status' => PrintJobStatusEnum::Pending,
            'file' => $this->file,
            'priority' => 1, // High priority for retries
            'retry_count' => 0, // Reset retry count for new job
            'retry_of' => $this->id, // Reference to original job
            'processing_machine_id' => null,
            'qz_job_name' => null,
            'last_qz_status' => null,
            'last_qz_message' => null,
            'error_message' => null,
            'printed_at' => null,
            'queued_at' => null,
            'started_at' => null,
            'failed_at' => null,
        ]);

        return $retryJob;
    }

    /**
     * Find an available printer for retry (not offline/paused and same type)
     */
    private function findAvailablePrinter(): int
    {
        $originalPrinter = $this->printer;

        // Find a printer of the same type that's not in error state
        $availablePrinter = Printer::where('type', $originalPrinter->type)
            ->where('is_active', true)
            ->whereNotIn('status', ['offline', 'paused'])
            ->orderBy('status') // Prefer idle printers over working ones
            ->first();

        // If no available printer found, fall back to original printer
        return $availablePrinter ? $availablePrinter->id : $this->printer_id;
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
        return $query->leftJoin('badges', function ($join) {
            $join->on('badges.id', '=', 'print_jobs.printable_id')
                ->where('print_jobs.printable_type', '=', 'App\\Models\\Badge\\Badge');
        })
            ->orderBy('print_jobs.priority', 'desc')
            ->orderByRaw('CAST(SUBSTRING_INDEX(badges.custom_id, "-", 1) AS UNSIGNED) ASC')
            ->orderByRaw('CAST(SUBSTRING_INDEX(badges.custom_id, "-", -1) AS UNSIGNED) ASC')
            ->orderBy('print_jobs.created_at', 'asc')
            ->select('print_jobs.*');
    }

    // State transition methods
    public function transitionTo(PrintJobStatusEnum $newStatus, ?string $errorMessage = null): bool
    {
        if (! $this->status->canTransitionTo($newStatus)) {
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
