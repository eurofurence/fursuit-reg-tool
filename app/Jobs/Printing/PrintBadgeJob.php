<?php

namespace App\Jobs\Printing;

use App\Badges\EF28_Badge;
use App\Badges\EF29_Badge;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Processing;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PrintBadgeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public function __construct(
        private readonly Badge $badge,
        private readonly ?int $printerId = null,
        private readonly int $priority = 1
    ) {
        $this->onQueue('batch-print');
    }

    public function handle(): void
    {
        // Check if batch was cancelled
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        Log::info('Processing print badge job', [
            'badge_id' => $this->badge->id,
            'custom_id' => $this->badge->custom_id,
            'priority' => $this->priority,
            'batch_id' => $this->batch()?->id,
        ]);

        // Badge should already be in Processing state from when the job was created
        // The transition to ReadyForPickup happens when the print job is marked as completed

        // Determine badge class based on event badge_class column
        $badgeClass = $this->badge->fursuit->event->badge_class ?? 'EF28_Badge';

        $printer = match ($badgeClass) {
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            default => new EF28_Badge, // Fallback to EF28 for safety
        };

        // Generate PDF content
        $pdfContent = $printer->getPdf($this->badge);

        // Store PDF Content in PrintJobs Storage
        $filePath = 'badges/'.$this->badge->id.'.pdf';
        Storage::put($filePath, $pdfContent);

        // Use specified printer if provided, otherwise find available printer
        if ($this->printerId) {
            $sendTo = Printer::where('id', $this->printerId)
                ->where('is_active', true)
                ->where('type', 'badge')
                ->first();

            if (! $sendTo) {
                throw new \Exception("Specified printer (ID: {$this->printerId}) not found or not active.");
            }
        } else {
            // Find available printer - only check is_active to allow queuing even if paused
            $sendTo = Printer::where('is_active', true)
                ->where('type', 'badge')
                ->orderBy('status') // Prefer idle printers over working ones
                ->first();

            if (! $sendTo) {
                throw new \Exception('No available badge printers found. All badge printers are inactive.');
            }
        }

        // Create PrintJob with priority support
        $printJob = $this->badge->printJobs()->create([
            'printer_id' => $sendTo->id,
            'type' => PrintJobTypeEnum::Badge,
            'status' => PrintJobStatusEnum::Pending,
            'file' => $filePath,
            'priority' => $this->priority,
            'queued_at' => now(),
        ]);

        Log::info('Badge print job created successfully', [
            'badge_id' => $this->badge->id,
            'print_job_id' => $printJob->id,
            'printer' => $sendTo->name,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Badge print job failed', [
            'badge_id' => $this->badge->id,
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        // Create failed print job record if we have a printer
        $fallbackPrinter = Printer::where('is_active', true)
            ->where('type', 'badge')
            ->first();

        if ($fallbackPrinter) {
            $this->badge->printJobs()->create([
                'printer_id' => $fallbackPrinter->id,
                'type' => PrintJobTypeEnum::Badge,
                'status' => PrintJobStatusEnum::Failed,
                'file' => null, // File might not have been created yet
                'error_message' => $exception?->getMessage(),
                'failed_at' => now(),
                'queued_at' => now(),
            ]);
        }
    }
}
