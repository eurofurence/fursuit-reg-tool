<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Models\Badge\Badge;
use App\Models\Machine;
use Laravel\Sanctum\Sanctum;

/**
 * Reprinting one bad card without stopping the run.
 *
 * Distinct from failing a job, which pauses the batch and fetches a human. A
 * card that came out smudged needs printing again; the twenty-three good cards
 * queued behind it do not need stopping.
 */
function runningBatch(int $cards = 3): PrintBatch
{
    $printer = Printer::factory()->badge()->create();
    $machine = $printer->machine ?? Machine::factory()->create();
    $printer->forceFill(['machine_id' => $machine->id])->save();
    Sanctum::actingAs($machine, ['*'], 'sanctum');

    $batch = PrintBatch::build('Friday', Badge::factory()->withPrintFile()->count($cards)->create(), $printer);
    $batch->transitionTo(PrintBatchStatusEnum::Ready);
    $batch->transitionTo(PrintBatchStatusEnum::Printing);

    return $batch->fresh();
}

function printFirstCard(PrintBatch $batch): PrintJob
{
    $job = $batch->printJobs()->orderBy('sequence')->first();
    $job->claim(Machine::factory()->create(), 180);
    $job->fresh()->transitionTo(PrintJobStatusEnum::Printing);
    $job->fresh()->transitionTo(PrintJobStatusEnum::Printed);

    return $job->fresh();
}

it('queues the card again without pausing the batch', function () {
    $batch = runningBatch();
    $job = printFirstCard($batch);

    $replacement = $job->reprintCard('came out smudged');

    expect($replacement->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($replacement->printable_id)->toBe($job->printable_id)
        ->and($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Printing);
});

it('puts the replacement at the end of the run', function () {
    // Not in the middle of a sequence that is already half-filed into bins.
    $batch = runningBatch(3);
    $job = printFirstCard($batch);

    $replacement = $job->reprintCard();

    expect($replacement->sequence)->toBe(4);
});

it('keeps the printed card on the record', function () {
    // Printed is terminal on purpose: the card existed and somebody rejected
    // it, which is worth keeping rather than rewriting.
    $batch = runningBatch();
    $job = printFirstCard($batch);

    $job->reprintCard('half transferred');

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Printed)
        ->and($job->fresh()->error_message)->toBe('half transferred');
});

it('keeps the badge locked while it waits to be printed again', function () {
    $batch = runningBatch();
    $job = printFirstCard($batch);

    $job->reprintCard();

    expect($job->printable->fresh()->isPrintingLocked())->toBeTrue();
});

it('counts the replacement in the batch totals', function () {
    $batch = runningBatch(3);
    $job = printFirstCard($batch);

    $job->reprintCard();

    expect($batch->fresh()->total_jobs)->toBe(4);
});

it('refuses when the batch is already finished', function () {
    // Nothing to add the card back to. The operator prints it from the POS,
    // which builds a fresh batch.
    $batch = runningBatch(1);
    $job = printFirstCard($batch);
    $batch->fresh()->completeIfFinished();

    expect($job->fresh()->reprintCard())->toBeNull();
});

it('is reachable over the agent API', function () {
    $batch = runningBatch();
    $job = printFirstCard($batch);

    $this->postJson("/api/print-agent/jobs/{$job->id}/reprint", ['reason' => 'smudged'])
        ->assertOk()
        ->assertJsonPath('batch_status', 'printing');

    expect($batch->fresh()->printJobs()->where('status', PrintJobStatusEnum::Pending)->count())
        ->toBe(3);
});

it('tells the agent plainly when the batch has closed', function () {
    $batch = runningBatch(1);
    $job = printFirstCard($batch);
    $batch->fresh()->completeIfFinished();

    $this->postJson("/api/print-agent/jobs/{$job->id}/reprint")
        ->assertStatus(409)
        ->assertJsonStructure(['error']);
});
