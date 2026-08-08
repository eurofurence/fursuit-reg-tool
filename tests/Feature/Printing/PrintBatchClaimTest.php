<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Models\Machine;

/**
 * Regressions for the failure modes that lost badges under the old QZ system:
 * the same job being handed out twice, a claimed job stranded by a dead agent,
 * and a job reaching Printed without anything having confirmed a card came out.
 */
function batchWithJobs(int $count, array $jobAttributes = []): array
{
    $printer = Printer::factory()->badge()->create();
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $printer->id]);

    $jobs = collect(range(1, $count))->map(fn (int $sequence) => PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'print_batch_id' => $batch->id,
        'sequence' => $sequence,
        'status' => PrintJobStatusEnum::Pending,
        ...$jobAttributes,
    ]));

    return [$batch, $jobs, $printer];
}

it('never hands the same job to two machines', function () {
    [$batch] = batchWithJobs(2);

    $first = $batch->claimNextJob(Machine::factory()->create());
    $second = $batch->claimNextJob(Machine::factory()->create());

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first->id)->not->toBe($second->id);
});

it('claims jobs in batch sequence order', function () {
    [$batch, $jobs] = batchWithJobs(3);
    $machine = Machine::factory()->create();

    $claimed = collect(range(1, 3))->map(fn () => $batch->claimNextJob($machine)->id);

    expect($claimed->all())->toBe($jobs->pluck('id')->all());
});

it('stops handing out jobs once the batch is exhausted', function () {
    [$batch] = batchWithJobs(1);
    $machine = Machine::factory()->create();

    expect($batch->claimNextJob($machine))->not->toBeNull()
        ->and($batch->claimNextJob($machine))->toBeNull();
});

it('refuses to hand out jobs from a paused batch', function () {
    [$batch] = batchWithJobs(2);
    $batch->pause('Card jam');

    expect($batch->fresh()->claimNextJob(Machine::factory()->create()))->toBeNull();
});

it('sets a lease and counts the attempt when claiming', function () {
    [$batch] = batchWithJobs(1);

    $job = $batch->claimNextJob(Machine::factory()->create(), leaseSeconds: 120);

    expect($job->status)->toBe(PrintJobStatusEnum::Queued)
        ->and($job->attempt_count)->toBe(1)
        ->and($job->lease_expires_at)->not->toBeNull()
        ->and($job->lease_expires_at->isFuture())->toBeTrue();
});

it('returns an abandoned job to the queue when its lease expires', function () {
    [$batch] = batchWithJobs(1);
    $job = $batch->claimNextJob(Machine::factory()->create());

    // The agent dies here: no heartbeat, no completion, nothing. Claimed but
    // never started, so no card exists and requeueing costs nothing.
    $job->update(['lease_expires_at' => now()->subMinute()]);

    $this->artisan('printing:reap-leases')->assertSuccessful();

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($job->fresh()->processing_machine_id)->toBeNull()
        ->and($job->fresh()->attempt_count)->toBe(1);
});

it('holds a job that died mid-print instead of queueing it again', function () {
    // This test used to assert the opposite, and the opposite is what printed
    // duplicates: once markPrinting() has run the artwork is with the spooler
    // and a card may be in the bin. Only a person can tell.
    [$batch] = batchWithJobs(1);
    $job = $batch->claimNextJob(Machine::factory()->create());
    $job->markPrinting();

    $job->update(['lease_expires_at' => now()->subMinute()]);

    $this->artisan('printing:reap-leases')->assertSuccessful();

    expect($job->fresh()->status)->not->toBe(PrintJobStatusEnum::Pending);
});

it('fails a job and pauses its batch once attempts are exhausted', function () {
    [$batch] = batchWithJobs(1);
    $job = $batch->printJobs()->first();
    $job->update([
        'status' => PrintJobStatusEnum::Queued,
        'attempt_count' => 3,
        'lease_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('printing:reap-leases')->assertSuccessful();

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Failed)
        ->and($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Paused);
});

it('leaves a healthy lease alone', function () {
    [$batch] = batchWithJobs(1);
    $job = $batch->claimNextJob(Machine::factory()->create(), leaseSeconds: 600);

    $this->artisan('printing:reap-leases')->assertSuccessful();

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Queued);
});

it('extends the lease on heartbeat', function () {
    [$batch] = batchWithJobs(1);
    $job = $batch->claimNextJob(Machine::factory()->create(), leaseSeconds: 60);
    $original = $job->lease_expires_at;

    $this->travel(5)->seconds();
    $job->heartbeat(300);

    expect($job->fresh()->lease_expires_at->greaterThan($original))->toBeTrue();
});

it('records how a job was completed', function () {
    [$batch] = batchWithJobs(1);
    $job = $batch->claimNextJob(Machine::factory()->create());
    $job->markPrinting();

    $job->markPrinted(PrintCompletionSourceEnum::Firmware, firmwareJobId: '58');

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Printed)
        ->and($job->fresh()->completion_source)->toBe(PrintCompletionSourceEnum::Firmware)
        ->and($job->fresh()->firmware_job_id)->toBe('58')
        ->and($job->fresh()->lease_expires_at)->toBeNull();
});

it('treats verification as independent of completion', function () {
    [$batch] = batchWithJobs(1);
    $job = $batch->claimNextJob(Machine::factory()->create());
    $job->markPrinting();
    $job->markPrinted(PrintCompletionSourceEnum::SpoolerOnly);

    // Printed but unconfirmed: the queue moved on, yet nothing has vouched for
    // the card, so it must still show up as needing a look.
    expect($job->fresh()->verified_print_at)->toBeNull()
        ->and(PrintJob::query()->unverified()->count())->toBe(1);

    $job->markVerified(PrintVerificationSourceEnum::Camera);

    expect($job->fresh()->verified_print_at)->not->toBeNull()
        ->and($job->fresh()->verification_source)->toBe(PrintVerificationSourceEnum::Camera)
        ->and(PrintJob::query()->unverified()->count())->toBe(0);
});

it('pauses the whole batch when a job fails', function () {
    [$batch] = batchWithJobs(3);
    $job = $batch->claimNextJob(Machine::factory()->create());

    $job->markFailed('Ribbon out');

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Paused)
        ->and($batch->fresh()->pause_reason)->toContain('Ribbon out')
        ->and($batch->fresh()->claimNextJob(Machine::factory()->create()))->toBeNull();
});

it('will not complete a batch that still has a failed job', function () {
    [$batch, $jobs] = batchWithJobs(2);
    $machine = Machine::factory()->create();

    $first = $batch->claimNextJob($machine);
    $first->markPrinting();
    $first->markPrinted(PrintCompletionSourceEnum::Firmware);

    $batch->fresh()->resume();
    $second = $batch->fresh()->claimNextJob($machine);
    $second->markFailed('Card jam');

    expect($batch->fresh()->completeIfFinished())->toBeFalse()
        ->and($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Paused);
});

it('completes a batch once every job has printed', function () {
    [$batch] = batchWithJobs(2);
    $machine = Machine::factory()->create();

    foreach (range(1, 2) as $ignored) {
        $job = $batch->fresh()->claimNextJob($machine);
        $job->markPrinting();
        $job->markPrinted(PrintCompletionSourceEnum::Firmware);
    }

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Completed)
        ->and($batch->fresh()->printed_count)->toBe(2);
});
