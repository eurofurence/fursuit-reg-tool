<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Models\Badge\Badge;
use App\Models\Machine;

/**
 * What a lapsed agent lease means depends on how far the card had got.
 *
 * Closing the agent mid-batch used to hand the in-flight card straight back to
 * the queue, so restarting printed a second copy of a card already sitting in
 * the output bin -- and could do it up to max-attempts times.
 */
function leaseBatch(int $cards = 3): PrintBatch
{
    $printer = Printer::factory()->badge()->create();
    $batch = PrintBatch::build('Friday', Badge::factory()->withPrintFile()->count($cards)->create(), $printer);
    $batch->transitionTo(PrintBatchStatusEnum::Ready);
    $batch->transitionTo(PrintBatchStatusEnum::Printing);

    return $batch->fresh();
}

function expiredJob(PrintBatch $batch, PrintJobStatusEnum $status)
{
    $job = $batch->printJobs()->orderBy('sequence')->first();
    $job->claim(Machine::factory()->create(), 180);

    if ($status === PrintJobStatusEnum::Printing) {
        $job->fresh()->transitionTo(PrintJobStatusEnum::Printing);
    }

    $job->fresh()->forceFill(['lease_expires_at' => now()->subMinutes(10)])->save();

    return $job->fresh();
}

it('requeues a claimed card, because nothing reached the printer', function () {
    $batch = leaseBatch();
    $job = expiredJob($batch, PrintJobStatusEnum::Queued);

    $this->artisan('printing:reap-leases')->assertExitCode(0);

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Printing);
});

it('does not requeue a card that was already printing', function () {
    // The artwork went to the spooler. A card may be in the bin, and printing
    // it again is a duplicate somebody has to spot by hand.
    $batch = leaseBatch();
    $job = expiredJob($batch, PrintJobStatusEnum::Printing);

    $this->artisan('printing:reap-leases')->assertExitCode(0);

    expect($job->fresh()->status)->not->toBe(PrintJobStatusEnum::Pending);
});

it('stops the batch so a person can look in the output bin', function () {
    $batch = leaseBatch();
    expiredJob($batch, PrintJobStatusEnum::Printing);

    $this->artisan('printing:reap-leases')->assertExitCode(0);

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Paused);
});

it('says plainly that the card may already exist', function () {
    $batch = leaseBatch();
    $job = expiredJob($batch, PrintJobStatusEnum::Printing);

    $this->artisan('printing:reap-leases')->assertExitCode(0);

    expect($job->fresh()->error_message)->toContain('may already be in the output bin');
});

it('never serves a mid-print card to another agent', function () {
    // The actual duplicate: reap, then let an agent claim from the batch again.
    $batch = leaseBatch();
    $job = expiredJob($batch, PrintJobStatusEnum::Printing);

    $this->artisan('printing:reap-leases')->assertExitCode(0);

    $next = $batch->fresh()->claimNextJob(Machine::factory()->create());

    expect($next?->id)->not->toBe($job->id);
});

it('still fails a claimed card that has been round too many times', function () {
    $batch = leaseBatch();
    $job = expiredJob($batch, PrintJobStatusEnum::Queued);
    $job->forceFill(['attempt_count' => 3])->save();

    $this->artisan('printing:reap-leases')->assertExitCode(0);

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Failed);
});
