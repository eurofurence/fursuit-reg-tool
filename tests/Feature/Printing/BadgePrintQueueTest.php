<?php

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Services\BadgePrintQueue;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use App\Models\EventUser;
use App\Models\Machine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Every print action funnels through BadgePrintQueue.
 *
 * The bug this locks out: each of the four print entry points used to build a
 * PrintJob directly and none set print_batch_id, so the agent's batch lane
 * never saw a job and the batch page stayed empty however much you printed.
 * The cards still came out, down a lane with no frozen order, no
 * pause-on-failure and no badge lock -- which is the whole reason batches
 * exist.
 */

/**
 * A badge that can actually be sent to a printer.
 *
 * The factory randomises fulfillment state, and ToProcessing allocates
 * custom_id off the attendee's event registration -- so without an EventUser a
 * badge blows up on the way to Processing, but only for the states that can
 * make that transition. Creating the registration keeps these tests about
 * batching rather than about which state the factory happened to pick.
 */
function queueableBadges(int $count = 1): Collection
{
    static $next = 1;

    return collect(range(1, $count))->map(function () use (&$next) {
        // custom_id up front, before withPrintFile() fingerprints the badge.
        // ToProcessing only allocates one when it is missing, and allocating it
        // afterwards would change the inputs and make the fresh print file
        // stale -- correct in production, where the id is printed on the card,
        // but here it just drags real PDF rendering into a batching test.
        $badge = Badge::factory()->withPrintFile()->create([
            'custom_id' => (1000 + $next++).'-1',
        ]);

        EventUser::factory()->create([
            'user_id' => $badge->fursuit->user_id,
            'event_id' => $badge->fursuit->event_id,
        ]);

        return $badge;
    });
}

it('creates a batch even for a single badge', function () {
    $badge = queueableBadges()->first();

    $batch = BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create());

    expect($batch)->not->toBeNull()
        ->and($batch->total_jobs)->toBe(1)
        ->and($batch->printJobs()->first()->print_batch_id)->toBe($batch->id);
});

it('leaves the batch selectable by the agent rather than a draft', function () {
    // build() produces a draft, and scopeSelectable() excludes drafts. A batch
    // nobody promotes is a batch the agent can never claim from, which is
    // indistinguishable from the print having been ignored.
    $badge = queueableBadges()->first();

    $batch = BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create());

    expect($batch->status)->toBe(PrintBatchStatusEnum::Ready)
        ->and(PrintBatch::selectable()->pluck('id'))->toContain($batch->id);
});

it('gives every job in the batch a sequence', function () {
    $badges = queueableBadges(3);

    $batch = BadgePrintQueue::queue($badges, Printer::factory()->badge()->create());

    expect($batch->printJobs()->pluck('sequence')->sort()->values()->all())->toBe([1, 2, 3]);
});

it('does not re-render artwork that is already current', function () {
    // Printing must never quietly change the card. A current file is handed to
    // the printer as-is; only a missing or stale one is rendered, and that path
    // is covered by BadgePrintFileTest rather than duplicated here.
    $badge = queueableBadges()->first();
    $before = $badge->print_file_generated_at;

    BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create());

    expect($badge->fresh()->print_file_generated_at->eq($before))->toBeTrue();
});

it('refuses to build a batch from artwork it cannot render', function () {
    // The badge has no print file and no image behind it, so generation fails.
    // Better to throw here than to seal a batch around a card that cannot print.
    $badge = Badge::factory()->create();

    EventUser::factory()->create([
        'user_id' => $badge->fursuit->user_id,
        'event_id' => $badge->fursuit->event_id,
    ]);

    Storage::fake();

    expect(fn () => BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create()))
        ->toThrow(RuntimeException::class);
});

it('locks the badges it queues', function () {
    $badge = queueableBadges()->first();

    BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create());

    expect($badge->fresh()->isPrintingLocked())->toBeTrue();
});

it('returns null rather than an empty batch when there is nothing to print', function () {
    expect(BadgePrintQueue::queue(collect()))->toBeNull();
});

/**
 * A retried job has to stay in its batch.
 */
it('keeps a retried job inside its batch', function () {
    $badges = Badge::factory()->withPrintFile()->count(2)->create();
    $batch = PrintBatch::build('Friday', $badges, Printer::factory()->badge()->create());
    $job = $batch->printJobs()->orderBy('sequence')->first();

    $retry = $job->createRetryJob();

    expect($retry->print_batch_id)->toBe($batch->id)
        ->and($retry->sequence)->toBe($job->sequence);
});

it('does not let a retried job leak into the unbatched lane', function () {
    // Dropping print_batch_id orphaned the card: the batch would never serve
    // it again and it became eligible for the lane with no guarantees.
    $printer = Printer::factory()->badge()->create();
    $badges = Badge::factory()->withPrintFile()->count(1)->create();
    $batch = PrintBatch::build('Friday', $badges, $printer);

    $batch->printJobs()->first()->createRetryJob();

    expect(PrintJob::claimNextUnbatched($printer, Machine::factory()->create()))
        ->toBeNull();
});

/**
 * The unbatched lane is for receipts.
 */
it('never hands a badge job to the unbatched lane', function () {
    $printer = Printer::factory()->badge()->create();
    $badge = Badge::factory()->withPrintFile()->create();

    $badge->printJobs()->create([
        'printer_id' => $printer->id,
        'type' => PrintJobTypeEnum::Badge,
        'status' => PrintJobStatusEnum::Pending,
        'file' => 'badges/stray.pdf',
    ]);

    expect(PrintJob::claimNextUnbatched($printer, Machine::factory()->create()))
        ->toBeNull();
});

it('still hands receipts to the unbatched lane', function () {
    $printer = Printer::factory()->create(['type' => PrintJobTypeEnum::Receipt]);
    $badge = Badge::factory()->create();

    $receipt = $badge->printJobs()->create([
        'printer_id' => $printer->id,
        'type' => PrintJobTypeEnum::Receipt,
        'status' => PrintJobStatusEnum::Pending,
        'file' => 'receipts/1.pdf',
    ]);

    expect(PrintJob::claimNextUnbatched($printer, Machine::factory()->create())?->id)
        ->toBe($receipt->id);
});
