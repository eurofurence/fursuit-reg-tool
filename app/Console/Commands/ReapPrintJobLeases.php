<?php

namespace App\Console\Commands;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
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
 *
 * What a lapsed lease means depends on how far the job had got, and the two
 * cases are not interchangeable:
 *
 * - Queued: claimed, but nothing has reached the printer. No card exists, so
 *   requeueing is free and another agent can pick it up.
 * - Printing: the artwork went to the spooler. A card may well be sitting in
 *   the output bin already, and the agent died before it could say so.
 *   Requeueing that prints a second copy of a card somebody has in their hand.
 *
 * Printing therefore stops and asks. Closing the agent mid-batch used to hand
 * the in-flight card straight back to the queue, so restarting reprinted a card
 * that had already come out -- up to max-attempts times.
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

        $held = 0;

        foreach ($expired as $job) {
            $age = $job->lease_expires_at->diffForHumans();

            // Mid-card when the agent went quiet. Only a person can see whether
            // the card is in the bin, so the batch stops rather than guessing.
            if ($job->status === PrintJobStatusEnum::Printing) {
                $job->markFailed(
                    "Agent stopped responding while this card was printing (lease expired {$age}). "
                    .'The card may already be in the output bin. Check before reprinting.'
                );
                $this->warn("Job #{$job->id}: was mid-print, batch paused for a human.");
                $held++;

                continue;
            }

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

        $this->info("Requeued {$requeued}, failed {$failed}, held for a human {$held}.");

        return self::SUCCESS;
    }
}
