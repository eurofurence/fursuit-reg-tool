<?php

namespace App\Jobs\Printing;

use App\Models\Badge\Badge;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class BatchPrintJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 3;

    public function __construct(
        public Collection $badges,
        public int $printerId
    ) {
        $this->onQueue('batch-print');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        Log::info('Starting batch print job', [
            'badge_count' => $this->badges->count(),
            'printer_id' => $this->printerId,
            'batch_id' => $this->batch()?->id ?? 'testing',
        ]);

        // Sort badges by attendee ID (lowest first), then by badge number (highest first within same attendee)
        $sortedBadges = $this->sortBadgesForPrinting($this->badges);

        // Create individual print job chains
        $jobChains = $sortedBadges->map(function (Badge $badge, $index) {
            return [
                new PrintBadgeJob($badge, $this->printerId, priority: 1),
            ];
        })->toArray();

        // Dispatch all job chains as a batch
        Bus::batch($jobChains)
            ->name("Badge Batch Print - {$this->badges->count()} badges")
            ->onQueue('batch-print')
            ->allowFailures()
            ->then(function () {
                Log::info('Batch print completed successfully', [
                    'batch_id' => $this->batch()?->id ?? 'testing',
                ]);
            })
            ->catch(function () {
                Log::error('Batch print failed', [
                    'batch_id' => $this->batch()?->id ?? 'testing',
                ]);
            })
            ->dispatch();

        Log::info('Batch print job chains dispatched', [
            'job_chains' => count($jobChains),
            'batch_id' => $this->batch()?->id ?? 'testing',
        ]);
    }

    /**
     * Sort badges for printing: lowest attendee ID first, then highest badge number within same attendee
     */
    private function sortBadgesForPrinting(Collection $badges): Collection
    {
        return $badges->sort(function (Badge $a, Badge $b) {
            // Extract attendee ID and badge number from custom_id (format: "123-2")
            $aCustomId = $a->custom_id;
            $bCustomId = $b->custom_id;

            // Handle null custom_ids - put them at the end
            if (! $aCustomId && ! $bCustomId) {
                return 0;
            }
            if (! $aCustomId) {
                return 1; // Put null custom_ids after non-null ones
            }
            if (! $bCustomId) {
                return -1; // Put non-null custom_ids before null ones
            }

            // Split custom_id into attendee_id and badge_number
            $aParts = explode('-', $aCustomId, 2);
            $bParts = explode('-', $bCustomId, 2);

            // Handle malformed custom_ids
            if (count($aParts) !== 2 || count($bParts) !== 2) {
                return 0;
            }

            [$aAttendeeId, $aBadgeNumber] = $aParts;
            [$bAttendeeId, $bBadgeNumber] = $bParts;

            // Convert to integers for proper comparison
            $aAttendeeId = (int) $aAttendeeId;
            $bAttendeeId = (int) $bAttendeeId;
            $aBadgeNumber = (int) $aBadgeNumber;
            $bBadgeNumber = (int) $bBadgeNumber;

            // First sort by attendee ID (ascending: 14, 15, 16...)
            if ($aAttendeeId !== $bAttendeeId) {
                return $aAttendeeId <=> $bAttendeeId;
            }

            // Then sort by badge number (descending: 3, 2, 1 within same attendee)
            // This ensures 16-3, 16-2, 16-1 order as requested
            return $bBadgeNumber <=> $aBadgeNumber;
        })->values();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('BatchPrintJob failed', [
            'badge_count' => $this->badges->count(),
            'printer_id' => $this->printerId,
            'exception' => $exception->getMessage(),
            'batch_id' => $this->batch()?->id ?? 'testing',
        ]);
    }
}
