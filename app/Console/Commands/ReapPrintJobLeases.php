<?php

namespace App\Console\Commands;

use App\Domain\Printing\Models\PrintJob;
use Illuminate\Console\Command;

/**
 * Return print jobs whose agent has gone quiet back to the queue.
 *
 * An agent claims a job for a bounded time and renews that lease while the card
 * is printing. If the Windows host reboots, the process is killed or the network
 * drops for long enough, the lease lapses and the job would otherwise sit
 * claimed forever. This puts it back so another attempt can pick it up.
 *
 * Jobs that have been attempted too many times are failed instead of looping,
 * which pauses their batch and puts the problem in front of a human.
 */
class ReapPrintJobLeases extends Command
{
    protected $signature = 'printing:reap-leases {--max-attempts=3 : Fail a job rather than requeue it past this many attempts}';

    protected $description = 'Requeue print jobs whose agent lease has expired';

    public function handle(): int
    {
        $maxAttempts = (int) $this->option('max-attempts');

        $expired = PrintJob::query()->leaseExpired()->with(['batch', 'printer'])->get();

        if ($expired->isEmpty()) {
            $this->info('No expired print job leases.');

            return self::SUCCESS;
        }

        $requeued = 0;
        $failed = 0;

        foreach ($expired as $job) {
            $age = $job->lease_expires_at->diffForHumans();

            if ($job->attempt_count >= $maxAttempts) {
                $job->markFailed("Agent stopped responding after {$job->attempt_count} attempts (lease expired {$age}).");
                $this->warn("Job #{$job->id}: failed after {$job->attempt_count} attempts, batch paused.");
                $failed++;

                continue;
            }

            $job->releaseLease("Lease expired {$age}");
            $this->line("Job #{$job->id}: returned to queue (attempt {$job->attempt_count}).");
            $requeued++;
        }

        $this->info("Requeued {$requeued}, failed {$failed}.");

        return self::SUCCESS;
    }
}
