<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Machine;
use Laravel\Sanctum\Sanctum;

/**
 * The receipt lane of `POST /jobs/claim`.
 *
 * Receipts arrive from a sale that already happened, print on a thermal printer
 * that has nothing to do with the card run, and are never part of a batch, so
 * there is no batch to claim them through. They still have to obey every rule
 * the card lane does: one job to one machine, and never another machine's work.
 */
function receiptStation(int $jobCount = 2): array
{
    $machine = Machine::factory()->create();
    $printer = Printer::factory()->receipt()->create(['machine_id' => $machine->id]);

    $jobs = collect(range(1, $jobCount))->map(fn () => PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'print_batch_id' => null,
        'sequence' => null,
        'status' => PrintJobStatusEnum::Pending,
        'type' => PrintJobTypeEnum::Receipt,
        'file' => null,
    ]));

    Sanctum::actingAs($machine, ['*']);

    return [$machine, $printer, $jobs];
}

it('claims a batch-less receipt by printer name', function () {
    [, $printer] = receiptStation(1);

    $job = $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $printer->name])
        ->assertOk()
        ->json('job');

    expect($job)->not->toBeNull()
        ->and($job['type'])->toBe('receipt')
        ->and($job['lease_expires_at'])->not->toBeNull()
        ->and(PrintJob::find($job['id'])->status)->toBe(PrintJobStatusEnum::Queued);
});

it('never hands the same receipt to two pollers', function () {
    // The whole reason the agent claims through the server rather than reading
    // the queue itself: two poll cycles must not both walk off with one job and
    // print the same receipt twice.
    [, $printer] = receiptStation(2);

    $first = $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $printer->name])->json('job');
    $second = $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $printer->name])->json('job');

    expect($first['id'])->not->toBe($second['id']);
});

it('hands out receipts oldest first', function () {
    [, $printer, $jobs] = receiptStation(3);

    $claimed = collect(range(1, 3))->map(
        fn () => $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $printer->name])->json('job.id')
    );

    // People are standing at the counter in the order their sales completed.
    expect($claimed->all())->toBe($jobs->pluck('id')->all());
});

it('returns no job once the receipt queue is empty', function () {
    [, $printer] = receiptStation(1);

    $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $printer->name])->assertOk();

    $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $printer->name])
        ->assertOk()
        ->assertJson(['job' => null]);
});

it('never returns a batched card through the receipt lane', function () {
    // A card belongs to a batch and prints in a frozen sequence under an
    // operator who chose that batch. Letting the batch-less lane pull one out
    // would print it out of order, on the wrong printer, outside any run.
    $machine = Machine::factory()->create();
    $printer = Printer::factory()->badge()->create(['machine_id' => $machine->id]);
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $printer->id]);

    PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'print_batch_id' => $batch->id,
        'sequence' => 1,
        'status' => PrintJobStatusEnum::Pending,
        'file' => null,
    ]);

    Sanctum::actingAs($machine, ['*']);

    $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $printer->name])
        ->assertOk()
        ->assertJson(['job' => null]);
});

it('will not let one machine claim another machine\'s receipt', function () {
    // Two tills side by side. Claiming by printer name must not become a way to
    // reach across to the other one's queue and take its paper.
    [, $ourPrinter] = receiptStation(1);

    $theirPrinter = Printer::factory()->receipt()->create(['machine_id' => Machine::factory()->create()->id]);
    $theirJob = PrintJob::factory()->create([
        'printer_id' => $theirPrinter->id,
        'print_batch_id' => null,
        'status' => PrintJobStatusEnum::Pending,
        'type' => PrintJobTypeEnum::Receipt,
        'file' => null,
    ]);

    $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $theirPrinter->name])
        ->assertNotFound();

    expect($theirJob->fresh()->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($theirJob->fresh()->processing_machine_id)->toBeNull();

    // Our own printer still works, so the refusal above is about ownership and
    // not about the lane being broken.
    $this->postJson('/api/print-agent/jobs/claim', ['printer_name' => $ourPrinter->name])
        ->assertOk()
        ->assertJsonPath('job.id', PrintJob::query()->where('printer_id', $ourPrinter->id)->value('id'));
});

it('refuses a claim that names neither a batch nor a printer', function () {
    // Without one or the other there is no way to tell which queue the agent is
    // asking about, and guessing would hand it somebody else's work.
    receiptStation(1);

    $this->postJson('/api/print-agent/jobs/claim', [])
        ->assertJsonValidationErrors(['batch_id', 'printer_name']);
});

it('still serves the card lane when a batch is named', function () {
    // The receipt lane is an addition. A claim carrying batch_id must behave
    // exactly as it did before.
    $machine = Machine::factory()->create();
    $printer = Printer::factory()->badge()->create(['machine_id' => $machine->id]);
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $printer->id]);

    PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'print_batch_id' => $batch->id,
        'sequence' => 1,
        'status' => PrintJobStatusEnum::Pending,
        'file' => null,
    ]);

    Sanctum::actingAs($machine, ['*']);

    $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])
        ->assertOk()
        ->assertJsonPath('job.sequence', 1);
});
