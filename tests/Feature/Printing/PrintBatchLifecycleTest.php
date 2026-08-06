<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrintJobStatusEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\Sanctum;

/**
 * Batches are immutable once built, and committing a badge to one takes it out
 * of the attendee's hands. The artwork is rendered before the run starts, so an
 * edit afterwards would put a card in the stack that no longer matches the
 * order and nobody would notice until pickup.
 */
function badgesFor(int $count): Collection
{
    // withPrintFile() because a batch can only be built from artwork that is
    // already rendered and still current. These tests are about what happens
    // once the badges are in the batch, not about the generation gate.
    return Badge::factory()->withPrintFile()->count($count)->create();
}

it('freezes the contents of a batch when it is built', function () {
    $badges = badgesFor(3);
    $batch = PrintBatch::build('Friday 14:00', $badges, Printer::factory()->badge()->create());

    expect($batch->printJobs()->count())->toBe(3)
        ->and($batch->total_jobs)->toBe(3)
        ->and($batch->isSealed())->toBeTrue()
        ->and($batch->status)->toBe(PrintBatchStatusEnum::Draft);
});

it('locks every badge the moment it enters a batch', function () {
    $badges = badgesFor(2);

    expect($badges->every(fn (Badge $badge) => ! $badge->isPrintingLocked()))->toBeTrue();

    PrintBatch::build('Friday 14:00', $badges, Printer::factory()->badge()->create());

    foreach ($badges as $badge) {
        expect($badge->fresh()->isPrintingLocked())->toBeTrue();
    }
});

it('stops the attendee editing a badge once it is in a batch', function () {
    // The factory randomises fulfillment state, and the policy independently
    // requires a Pending badge during a running event. Pin both: this test is
    // about the batch lock, not the order window.
    $badge = Badge::factory()->withPrintFile()->create([
        'status_fulfillment' => Pending::$name,
        'extra_copy_of' => null,
    ]);
    $owner = $badge->fursuit->user;

    $badge->fursuit->event->update([
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(3),
    ]);

    expect($owner->can('update', $badge))->toBeTrue();

    PrintBatch::build('Friday 14:00', collect([$badge]), Printer::factory()->badge()->create());

    expect($owner->can('update', $badge->fresh()))->toBeFalse()
        ->and($owner->can('delete', $badge->fresh()))->toBeFalse();
});

it('prints highest attendee and badge number first', function () {
    $badges = collect([
        Badge::factory()->withPrintFile()->create(['custom_id' => '2024-1']),
        Badge::factory()->withPrintFile()->create(['custom_id' => '2025-1']),
        Badge::factory()->withPrintFile()->create(['custom_id' => '2025-2']),
    ]);

    $batch = PrintBatch::build('Ordering', $badges, Printer::factory()->badge()->create());

    $order = $batch->printJobs()->orderBy('sequence')->get()
        ->map(fn ($job) => $job->printable->custom_id)
        ->all();

    // Printed in this order so the finished stack reads ascending from the top.
    expect($order)->toBe(['2025-2', '2025-1', '2024-1']);
});

it('cancels a batch and everything still queued in it', function () {
    $batch = PrintBatch::build('Friday 14:00', badgesFor(3), Printer::factory()->badge()->create());
    $batch->transitionTo(PrintBatchStatusEnum::Ready);
    $batch->transitionTo(PrintBatchStatusEnum::Printing);

    // One card makes it out before the operator gives up on the printer.
    $first = $batch->claimNextJob(Machine::factory()->create());
    $first->markPrinting();
    $first->markPrinted(PrintCompletionSourceEnum::Firmware);

    expect($batch->fresh()->cancel('Printer died'))->toBeTrue();

    $statuses = $batch->fresh()->printJobs()->orderBy('sequence')->pluck('status');

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($statuses[0])->toBe(PrintJobStatusEnum::Printed)
        ->and($statuses[1])->toBe(PrintJobStatusEnum::Cancelled)
        ->and($statuses[2])->toBe(PrintJobStatusEnum::Cancelled);
});

it('hands editing back to attendees whose card never printed', function () {
    $badges = badgesFor(2);
    $batch = PrintBatch::build('Friday 14:00', $badges, Printer::factory()->badge()->create());
    $batch->transitionTo(PrintBatchStatusEnum::Ready);
    $batch->transitionTo(PrintBatchStatusEnum::Printing);

    $printed = $batch->claimNextJob(Machine::factory()->create());
    $printed->markPrinting();
    $printed->markPrinted(PrintCompletionSourceEnum::Firmware);
    $printedBadge = $printed->printable;

    $batch->fresh()->cancel('Ribbon ran out');

    $untouched = $badges->firstWhere('id', '!=', $printedBadge->id);

    // A card exists for this one, so it stays locked.
    expect($printedBadge->fresh()->isPrintingLocked())->toBeTrue()
        // Nothing was ever printed for this one, so the attendee gets it back.
        ->and($untouched->fresh()->isPrintingLocked())->toBeFalse();
});

it('will not hand out more work from a cancelled batch', function () {
    $batch = PrintBatch::build('Friday 14:00', badgesFor(2), Printer::factory()->badge()->create());
    $batch->transitionTo(PrintBatchStatusEnum::Ready);
    $batch->transitionTo(PrintBatchStatusEnum::Printing);

    $batch->fresh()->cancel('Operator stopped it');

    expect($batch->fresh()->claimNextJob(Machine::factory()->create()))->toBeNull();
});

it('cannot cancel a batch twice', function () {
    $batch = PrintBatch::build('Friday 14:00', badgesFor(1), Printer::factory()->badge()->create());

    expect($batch->cancel())->toBeTrue()
        ->and($batch->fresh()->cancel())->toBeFalse();
});

it('treats starting an already printing batch as a no-op', function () {
    // The agent re-asserts the start whenever it is unsure: after an
    // unattended hand-off to the next batch, and after a resume. Answering 409
    // there stopped a queue that was printing perfectly well.
    $printer = Printer::factory()->badge()->create();
    $machine = $printer->machine ?? Machine::factory()->create();
    $printer->forceFill(['machine_id' => $machine->id])->save();
    Sanctum::actingAs($machine, ['*'], 'sanctum');

    $batch = PrintBatch::build('Friday', Badge::factory()->withPrintFile()->count(1)->create(), $printer);
    $batch->transitionTo(PrintBatchStatusEnum::Ready);

    $start = fn () => test()->postJson("/api/print-agent/batches/{$batch->id}/start", [
        'printer_name' => $printer->name,
    ]);

    $start()->assertOk();
    $start()->assertOk();

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Printing);
});
