<?php

namespace App\Console\Commands;

use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrinterStatusEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStuckPrintJobs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'printing:check-stuck-jobs';

    /**
     * The console command description.
     */
    protected $description = 'Check for print jobs stuck in printing state and mark printers as needing attention';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for stuck print jobs...');

        // Find print jobs that have been in "printing" state for longer than 5 minutes
        $stuckJobs = PrintJob::where('status', PrintJobStatusEnum::Printing)
            ->where('started_at', '<', now()->subMinutes(5))
            ->with(['printer', 'processingMachine'])
            ->get();

        if ($stuckJobs->isEmpty()) {
            $this->info('No stuck print jobs found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$stuckJobs->count()} stuck print jobs:");

        foreach ($stuckJobs as $job) {
            $this->line("- Job #{$job->id} on printer '{$job->printer->name}' (started: {$job->started_at->diffForHumans()})");

            // Check if this printer has processed any jobs successfully since this stuck job started
            $recentSuccessfulJobs = PrintJob::where('printer_id', $job->printer_id)
                ->where('status', PrintJobStatusEnum::Printed)
                ->where('printed_at', '>', $job->started_at)
                ->exists();

            if ($recentSuccessfulJobs) {
                $this->line("  ✓ Printer has processed jobs recently - skipping");
                continue;
            }

            // Mark printer as needing attention (paused state)
            $this->markPrinterAsStuck($job->printer, $job);
        }

        return Command::SUCCESS;
    }

    /**
     * Mark a printer as stuck/paused due to a stuck print job
     */
    private function markPrinterAsStuck(Printer $printer, PrintJob $stuckJob): void
    {
        // Update printer status to paused with error message
        Printer::updatePrinterState(
            $printer->name,
            PrinterStatusEnum::PAUSED,
            $stuckJob->id,
            "Print job #{$stuckJob->id} has been processing for over 5 minutes. Please check printer for paper jams, low ribbons, or other issues.",
            $stuckJob->processingMachine->name ?? 'System'
        );

        Log::warning("Printer '{$printer->name}' marked as paused due to stuck print job #{$stuckJob->id}", [
            'printer_id' => $printer->id,
            'job_id' => $stuckJob->id,
            'job_started_at' => $stuckJob->started_at,
            'stuck_duration_minutes' => $stuckJob->started_at->diffInMinutes(now())
        ]);

        $this->warn("  ⚠ Marked printer '{$printer->name}' as PAUSED - requires staff attention");
    }
}