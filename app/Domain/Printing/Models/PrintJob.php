<?php

namespace App\Domain\Printing\Models;

use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Machine;
use App\Models\User;
use Database\Factories\PrintJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrintJob extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $fillable = [
        'printer_id', 'print_batch_id', 'sequence', 'printable_type', 'printable_id',
        'type', 'status', 'file', 'priority', 'retry_count', 'retry_of',
        'processing_machine_id', 'attempt_count', 'lease_expires_at',
        'completion_source', 'verified_print_at', 'verification_source', 'verified_by_id',
        'firmware_job_id', 'firmware_job_uuid', 'error_message',
        'printed_at', 'queued_at', 'started_at', 'failed_at',
    ];

    protected static function newFactory()
    {
        return PrintJobFactory::new();
    }

    protected $casts = [
        'printed_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'failed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'verified_print_at' => 'datetime',
        'type' => PrintJobTypeEnum::class,
        'status' => PrintJobStatusEnum::class,
        'completion_source' => PrintCompletionSourceEnum::class,
        'verification_source' => PrintVerificationSourceEnum::class,
        'retry_count' => 'integer',
        'attempt_count' => 'integer',
        'priority' => 'integer',
        'sequence' => 'integer',
    ];

    public function printable()
    {
        return $this->morphTo();
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PrintBatch::class, 'print_batch_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
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
            // Stay in the batch. Dropping these orphaned a retried card out of
            // its run: the batch lane would never serve it again, the counters
            // stopped seeing it, and it fell through to the unbatched lane
            // instead -- printed, but outside every guard the batch provides.
            'print_batch_id' => $this->print_batch_id,
            'sequence' => $this->sequence,
            'printable_type' => $this->printable_type,
            'printable_id' => $this->printable_id,
            'type' => $this->type,
            'status' => PrintJobStatusEnum::Pending,
            'file' => $this->file,
            'priority' => 1, // High priority for retries
            'retry_count' => 0, // Reset retry count for new job
            'retry_of' => $this->id, // Reference to original job
            'processing_machine_id' => null,
            'firmware_job_id' => null,
            'firmware_job_uuid' => null,
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

    /**
     * Take ownership of this job on behalf of a machine for a bounded time.
     *
     * The lease is what makes a lost agent survivable. If the Windows host
     * reboots mid-run the job is not stranded in a claimed state forever; the
     * lease lapses and releaseExpiredLeases() puts it back in the queue.
     */
    public function claim(Machine $machine, int $leaseSeconds = 180): bool
    {
        if (! $this->status->canTransitionTo(PrintJobStatusEnum::Queued)) {
            return false;
        }

        return $this->update([
            'status' => PrintJobStatusEnum::Queued,
            'processing_machine_id' => $machine->id,
            'lease_expires_at' => now()->addSeconds($leaseSeconds),
            'attempt_count' => $this->attempt_count + 1,
            'queued_at' => now(),
        ]);
    }

    /**
     * Atomically hand the oldest batch-less job on a printer to a machine.
     *
     * The receipt lane. A receipt is printed the moment the sale completes, on a
     * thermal printer that has nothing to do with the card run, so it is never
     * part of a batch and there is no batch to claim it through. Everything else
     * is the same as PrintBatch::claimNextJob(): the row is locked for the
     * duration, because two pollers being handed the same job is exactly the
     * failure this rework exists to remove.
     *
     * Oldest first, by id. Receipts come out in the order the sales happened,
     * which is the order the people at the counter are standing in.
     */
    public static function claimNextUnbatched(Printer $printer, Machine $machine, int $leaseSeconds = 180): ?self
    {
        return DB::transaction(function () use ($printer, $machine, $leaseSeconds) {
            /** @var self|null $job */
            $job = self::query()
                ->where('printer_id', $printer->id)
                ->whereNull('print_batch_id')
                // Receipts only. This lane has none of the batch guarantees,
                // and badges must not reach it: every badge print now builds a
                // batch, so a batch-less badge job is a bug rather than work
                // to be quietly picked up.
                ->where('type', PrintJobTypeEnum::Receipt)
                ->where('status', PrintJobStatusEnum::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $job?->claim($machine, $leaseSeconds);

            return $job;
        });
    }

    /**
     * Extend the lease. The agent calls this while a card is still going
     * through the printer, which on a retransfer machine takes well over a
     * minute.
     */
    public function heartbeat(int $leaseSeconds = 180): bool
    {
        if (! $this->status->holdsLease()) {
            return false;
        }

        return $this->update(['lease_expires_at' => now()->addSeconds($leaseSeconds)]);
    }

    public function markPrinting(): bool
    {
        return $this->transitionTo(PrintJobStatusEnum::Printing);
    }

    /**
     * Record that the card finished printing, and say how we know.
     *
     * There is deliberately no way to reach Printed without naming a source.
     * The system this replaces completed jobs on a ten second timer and marked
     * jammed cards as printed.
     */
    public function markPrinted(
        PrintCompletionSourceEnum $source,
        ?string $firmwareJobId = null,
        ?string $firmwareJobUuid = null,
    ): bool {
        if (! $this->status->canTransitionTo(PrintJobStatusEnum::Printed)) {
            return false;
        }

        $updated = $this->update([
            'status' => PrintJobStatusEnum::Printed,
            'printed_at' => now(),
            'completion_source' => $source,
            'firmware_job_id' => $firmwareJobId ?? $this->firmware_job_id,
            'firmware_job_uuid' => $firmwareJobUuid ?? $this->firmware_job_uuid,
            'lease_expires_at' => null,
        ]);

        $this->releasePrinter();
        $this->promoteBadgeToReadyForPickup();
        $this->batch?->recalculateCounters();
        $this->batch?->completeIfFinished();

        return $updated;
    }

    /**
     * Stamp the card as verified.
     *
     * Independent of the print lifecycle on purpose: whether a card finished
     * and whether the right card came out are separate questions, answered by
     * separate calls. The camera frames and OCR behind a camera verification
     * never leave the agent machine; only the verdict is stored.
     */
    public function markVerified(PrintVerificationSourceEnum $source, ?User $verifiedBy = null): bool
    {
        $updated = $this->update([
            'verified_print_at' => now(),
            'verification_source' => $source,
            'verified_by_id' => $verifiedBy?->id,
        ]);

        if ($this->printable instanceof Badge) {
            $this->printable->forceFill(['verified_print_at' => now()])->saveQuietly();
        }

        $this->batch?->recalculateCounters();

        return $updated;
    }

    /**
     * Record a failure and stop the batch it belongs to.
     *
     * Halting is the point. A jam that only kills one job lets the rest of the
     * run drain onto a broken printer, which is how badges went missing.
     */
    /**
     * Print this card again, without stopping the run.
     *
     * For the card that came out smudged, half-transferred or plainly wrong.
     * The operator is holding it, the batch is midway through, and pausing
     * twenty-three good cards over one bad one is the wrong trade.
     *
     * A new job rather than un-printing this one. Printed is terminal on
     * purpose: the card physically existed and somebody rejected it, and that
     * is worth keeping. The replacement goes on the end of the batch so it
     * prints after the run rather than claiming a place in the middle of a
     * sequence that is already half-filed.
     *
     * Returns null when the batch can no longer take work.
     */
    public function reprintCard(string $reason = 'Card rejected at the printer'): ?self
    {
        $batch = $this->batch;

        if ($batch === null || $batch->status->isTerminal()) {
            return null;
        }

        $next = (int) $batch->printJobs()->max('sequence') + 1;

        $replacement = $batch->printJobs()->create([
            'printer_id' => $this->printer_id,
            'printable_type' => $this->printable_type,
            'printable_id' => $this->printable_id,
            'type' => $this->type,
            'status' => PrintJobStatusEnum::Pending,
            'file' => $this->file,
            'sequence' => $next,
            'priority' => 1,
            'retry_of' => $this->id,
            'error_message' => null,
        ]);

        // The badge is being printed again, so it stays committed to the run.
        $printable = $this->printable;

        if ($printable instanceof Badge && ! $printable->isPrintingLocked()) {
            $printable->forceFill(['printing_locked_at' => now()])->saveQuietly();
        }

        $this->update(['error_message' => $reason]);
        $batch->recalculateCounters();

        return $replacement;
    }

    /**
     * Put a failed job back in the queue.
     *
     * A failed card is not a lost card. Whatever stopped it -- a jam, an empty
     * ribbon, a printer that stopped answering -- is fixed by a person, and the
     * badge still needs printing. Leaving the job failed blocked the batch from
     * ever completing and left the card silently unprinted.
     *
     * The attempt count resets because a human has been round the loop: the
     * three-strikes rule exists to stop an agent retrying into a broken
     * printer, not to punish a badge.
     */
    public function requeue(): bool
    {
        if ($this->status !== PrintJobStatusEnum::Failed) {
            return false;
        }

        $this->transitionTo(PrintJobStatusEnum::Retrying);
        $this->transitionTo(PrintJobStatusEnum::Queued);
        $this->transitionTo(PrintJobStatusEnum::Pending);

        return $this->update([
            'error_message' => null,
            'failed_at' => null,
            'lease_expires_at' => null,
            'processing_machine_id' => null,
            'attempt_count' => 0,
        ]);
    }

    public function markFailed(string $reason): bool
    {
        if (! $this->status->canTransitionTo(PrintJobStatusEnum::Failed)) {
            return false;
        }

        $updated = $this->update([
            'status' => PrintJobStatusEnum::Failed,
            'failed_at' => now(),
            'error_message' => $reason,
            'lease_expires_at' => null,
        ]);

        $this->releasePrinter();
        $this->batch?->pause("Print job #{$this->id} failed: {$reason}");
        $this->batch?->recalculateCounters();

        return $updated;
    }

    /**
     * Return an abandoned job to the queue.
     */
    public function releaseLease(string $reason = 'Lease expired'): bool
    {
        if (! $this->status->canTransitionTo(PrintJobStatusEnum::Pending)) {
            return false;
        }

        Log::warning("Print job {$this->id} reclaimed: {$reason}", [
            'job_id' => $this->id,
            'machine_id' => $this->processing_machine_id,
            'attempt_count' => $this->attempt_count,
        ]);

        $this->releasePrinter();

        return $this->update([
            'status' => PrintJobStatusEnum::Pending,
            'processing_machine_id' => null,
            'lease_expires_at' => null,
            'queued_at' => null,
            'started_at' => null,
        ]);
    }

    /**
     * Jobs whose holder has gone quiet past the end of its lease.
     */
    public function scopeLeaseExpired($query)
    {
        return $query->whereIn('status', [PrintJobStatusEnum::Queued, PrintJobStatusEnum::Printing])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now());
    }

    public function scopeUnverified($query)
    {
        return $query->where('status', PrintJobStatusEnum::Printed)->whereNull('verified_print_at');
    }

    private function releasePrinter(): void
    {
        $this->printer?->update(['current_job_id' => null]);
    }

    private function promoteBadgeToReadyForPickup(): void
    {
        $badge = $this->printable;

        if (! $badge instanceof Badge) {
            return;
        }

        if ($badge->status_fulfillment->canTransitionTo(ReadyForPickup::class)) {
            $badge->status_fulfillment->transitionTo(ReadyForPickup::class);
        }
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
