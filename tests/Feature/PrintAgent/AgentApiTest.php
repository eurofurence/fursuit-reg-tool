<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Machine;
use Laravel\Sanctum\Sanctum;

/**
 * The print agent API. These lock in the properties that the QZ-based
 * predecessor got wrong: no cross-machine access, no completion without
 * evidence, and no way for a fault to be quietly skipped past.
 */
function agentSetup(int $jobCount = 2): array
{
    $machine = Machine::factory()->create();
    $printer = Printer::factory()->badge()->create(['machine_id' => $machine->id]);
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $printer->id]);

    collect(range(1, $jobCount))->each(fn (int $sequence) => PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'print_batch_id' => $batch->id,
        'sequence' => $sequence,
        'status' => PrintJobStatusEnum::Pending,
        'type' => PrintJobTypeEnum::Badge,
        'file' => null,
    ]));

    Sanctum::actingAs($machine, ['*']);

    return [$machine, $printer, $batch];
}

it('rejects an unauthenticated agent', function () {
    $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => 1])
        ->assertUnauthorized();
});

it('hands out one job per claim in sequence order', function () {
    [, , $batch] = agentSetup(2);

    $first = $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])
        ->assertOk()->json('job');

    $second = $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])
        ->assertOk()->json('job');

    expect($first['sequence'])->toBe(1)
        ->and($second['sequence'])->toBe(2)
        ->and($first['id'])->not->toBe($second['id'])
        ->and($first['lease_expires_at'])->not->toBeNull();
});

it('returns no job once the batch is drained', function () {
    [, , $batch] = agentSetup(1);

    $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])->assertOk();

    $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])
        ->assertOk()
        ->assertJson(['job' => null]);
});

it('will not let one machine touch another machine\'s job', function () {
    // A job belonging to somebody else entirely.
    $otherPrinter = Printer::factory()->badge()->create(['machine_id' => Machine::factory()->create()->id]);
    $otherJob = PrintJob::factory()->create(['printer_id' => $otherPrinter->id, 'file' => null]);

    agentSetup(1);

    $this->postJson("/api/print-agent/jobs/{$otherJob->id}/printed", [
        'completion_source' => 'firmware',
    ])->assertNotFound();

    $this->postJson("/api/print-agent/jobs/{$otherJob->id}/verify", ['source' => 'camera'])
        ->assertNotFound();

    expect($otherJob->fresh()->status)->toBe(PrintJobStatusEnum::Pending);
});

it('refuses to mark a job printed without naming a completion source', function () {
    [, , $batch] = agentSetup(1);
    $job = $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])->json('job');

    $this->postJson("/api/print-agent/jobs/{$job['id']}/printing")->assertOk();

    $this->postJson("/api/print-agent/jobs/{$job['id']}/printed", [])
        ->assertJsonValidationErrors('completion_source');

    expect(PrintJob::find($job['id'])->status)->toBe(PrintJobStatusEnum::Printing);
});

it('records a firmware-confirmed completion', function () {
    [, , $batch] = agentSetup(1);
    $job = $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])->json('job');

    $this->postJson("/api/print-agent/jobs/{$job['id']}/printing")->assertOk();
    $this->postJson("/api/print-agent/jobs/{$job['id']}/printed", [
        'completion_source' => 'firmware',
        'firmware_job_id' => '58',
        'firmware_job_uuid' => 'cf60b800-5bbc-4766-936e-5358a82a3670',
    ])->assertOk();

    $stored = PrintJob::find($job['id']);

    expect($stored->status)->toBe(PrintJobStatusEnum::Printed)
        ->and($stored->firmware_job_id)->toBe('58')
        // Printed is not the same as verified. Verification is its own call.
        ->and($stored->verified_print_at)->toBeNull();
});

it('verifies a card through a separate call', function () {
    [, , $batch] = agentSetup(1);
    $job = $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])->json('job');

    $this->postJson("/api/print-agent/jobs/{$job['id']}/printing")->assertOk();
    $this->postJson("/api/print-agent/jobs/{$job['id']}/printed", ['completion_source' => 'firmware'])->assertOk();
    $this->postJson("/api/print-agent/jobs/{$job['id']}/verify", ['source' => 'camera'])->assertOk();

    expect(PrintJob::find($job['id'])->verified_print_at)->not->toBeNull();
});

it('pauses the batch and stops handing out work when a job fails', function () {
    [, , $batch] = agentSetup(3);
    $job = $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])->json('job');

    $this->postJson("/api/print-agent/jobs/{$job['id']}/failed", ['reason' => 'Card jam'])
        ->assertOk()
        ->assertJson(['batch_status' => 'paused']);

    $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])
        ->assertOk()
        ->assertJson(['job' => null, 'batch_status' => 'paused']);
});

it('extends a lease on heartbeat', function () {
    [, , $batch] = agentSetup(1);
    $job = $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])->json('job');

    $this->travel(10)->seconds();

    $response = $this->postJson("/api/print-agent/jobs/{$job['id']}/heartbeat")->assertOk();

    expect($response->json('extended'))->toBeTrue()
        ->and($response->json('lease_expires_at'))->toBeGreaterThan($job['lease_expires_at']);
});

it('survives a printable that was soft deleted', function () {
    [, , $batch] = agentSetup(1);

    // The old polling endpoint dereferenced the printable with no null guard, so
    // a single deleted badge 500'd the request and stopped every printer.
    PrintJob::query()->first()->printable->delete();

    $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])
        ->assertOk()
        ->assertJsonPath('job.duplex', false);
});

it('reports what the agent is currently holding', function () {
    [, , $batch] = agentSetup(2);
    $this->postJson('/api/print-agent/jobs/claim', ['batch_id' => $batch->id])->json('job');

    $this->getJson('/api/print-agent/jobs/held')
        ->assertOk()
        ->assertJsonCount(1, 'jobs');
});

it('records a printer stop condition for the POS', function () {
    [, $printer] = agentSetup(1);

    $this->postJson('/api/print-agent/printers/condition', [
        'printer_name' => $printer->name,
        'condition' => 'card_jam',
        'cards_remaining' => 313,
        'cards_capacity' => 627,
    ])->assertOk()->assertJson(['is_stop' => true]);

    expect($printer->fresh()->condition)->toBe('card_jam')
        ->and($printer->fresh()->cards_remaining)->toBe(313);
});

it('treats an unrecognised printer reading as a stop', function () {
    [, $printer] = agentSetup(1);

    // Never assume silence means healthy. That assumption is what let cards be
    // recorded as printed while the printer sat jammed.
    $this->postJson('/api/print-agent/printers/condition', [
        'printer_name' => $printer->name,
        'condition' => 'some_firmware_string_we_have_never_seen',
    ])->assertOk()->assertJson(['condition' => 'unknown', 'is_stop' => true]);
});

it('only starts a batch on a printer belonging to the caller', function () {
    [, $printer, $batch] = agentSetup(1);
    $batch->update(['printer_id' => null, 'status' => PrintBatchStatusEnum::Ready]);

    $this->postJson("/api/print-agent/batches/{$batch->id}/start", [
        'printer_name' => 'A Printer That Is Not Ours',
    ])->assertNotFound();

    $this->postJson("/api/print-agent/batches/{$batch->id}/start", [
        'printer_name' => $printer->name,
    ])->assertOk()->assertJsonPath('batch.status', 'printing');
});

it('records agent liveness on every call', function () {
    [$machine] = agentSetup(1);

    expect($machine->fresh()->agent_last_seen_at)->toBeNull();

    $this->withHeader('X-Agent-Version', '1.0.0')
        ->getJson('/api/print-agent/config')
        ->assertOk();

    expect($machine->fresh()->agent_last_seen_at)->not->toBeNull()
        ->and($machine->fresh()->agent_version)->toBe('1.0.0');
});
