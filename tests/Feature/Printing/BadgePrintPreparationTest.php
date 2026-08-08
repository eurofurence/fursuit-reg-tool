<?php

/*
 * Preparing a print run happens on a queue, and either finishes or undoes itself.
 *
 * The bug behind this: rendering used to happen inline, in the request the operator
 * pressed Print in. Each card is an S3 read, a GD decode of a phone photo and an mpdf
 * render, so a bulk selection of badges nobody had rendered yet ran past PHP's 30 second
 * limit and was killed partway through (Sentry c5c679fb). What it left behind is the real
 * damage and what these tests lock out: every badge in the selection sitting in Processing
 * with a custom_id allocated, no batch, no print jobs and nothing on screen to say the run
 * did not exist. The badges read as "sent to the printer" and no card was ever coming.
 *
 * So: the request does no work, and a preparation that fails puts the badges back.
 */

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Services\BadgePrintQueue;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Jobs\Printing\PrepareBadgePrintBatchJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Models\EventUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Badges that can be sent to a printer, with artwork already rendered.
 *
 * Same shape as BadgePrintQueueTest's helper and for the same reason: custom_id up front,
 * so withPrintFile()'s fingerprint stays current and these tests are about preparation
 * rather than about mpdf.
 */
function preparableBadges(int $count = 1): Collection
{
    static $next = 5000;

    return collect(range(1, $count))->map(function () use (&$next) {
        $badge = Badge::factory()->withPrintFile()->create([
            'status_fulfillment' => Pending::$name,
            'custom_id' => ($next++).'-1',
        ]);

        EventUser::factory()->create([
            'user_id' => $badge->fursuit->user_id,
            'event_id' => $badge->fursuit->event_id,
        ]);

        return $badge;
    });
}

/** A badge with no artwork and no image behind it: rendering it throws. */
function unrenderableBadge(): Badge
{
    $badge = Badge::factory()->create(['status_fulfillment' => Pending::$name]);

    EventUser::factory()->create([
        'user_id' => $badge->fursuit->user_id,
        'event_id' => $badge->fursuit->event_id,
    ]);

    return $badge;
}

it('does no work in the request that presses print', function () {
    Queue::fake();

    $badges = preparableBadges(3);

    $batch = BadgePrintQueue::queue($badges, Printer::factory()->badge()->create());

    // A Draft holding nothing: no agent can claim out of it, so a run that is still being
    // prepared cannot put a card through a printer.
    expect($batch->status)->toBe(PrintBatchStatusEnum::Draft)
        ->and($batch->printJobs()->count())->toBe(0)
        ->and(PrintBatch::selectable()->pluck('id'))->not->toContain($batch->id);

    // And the badges have not moved. The transition allocates custom_id and belongs with
    // the render, on the worker.
    $badges->each(fn (Badge $badge) => expect($badge->fresh()->status_fulfillment)->toBeInstanceOf(Pending::class));

    Queue::assertPushed(
        PrepareBadgePrintBatchJob::class,
        fn (PrepareBadgePrintBatchJob $job) => $job->batch->is($batch)
            && $job->badgeIds === $badges->map(fn (Badge $b) => (int) $b->id)->all()
    );
});

it('renders on the badge-render queue rather than beside the panel', function () {
    Queue::fake();

    BadgePrintQueue::queue(preparableBadges(), Printer::factory()->badge()->create());

    Queue::assertPushed(
        PrepareBadgePrintBatchJob::class,
        fn (PrepareBadgePrintBatchJob $job) => $job->queue === 'badge-render'
    );
});

/*
 * The second half of that bug. Moving the work to a queue stopped the request dying, but
 * the connection carrying it re-served the job after 90 seconds while it was still
 * rendering: a second worker found attempts past tries = 1 and failed it with
 * "App\Jobs\Printing\PrepareBadgePrintBatchJob has been attempted too many times", whose
 * failed() hook cancelled the batch and returned the badges under the run still building
 * them. A hundred cards is minutes of work, so retry_after has to clear the job's timeout.
 */
it('reserves a run for longer than it can take to prepare', function (string $connection) {
    $timeout = (new ReflectionClass(PrepareBadgePrintBatchJob::class))
        ->getDefaultProperties()['timeout'];

    expect(config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($timeout);
})->with(['database-long-running', 'redis-long-running']);

it('prepares runs on the long-running connection, not the shared one', function () {
    Queue::fake();

    BadgePrintQueue::queue(preparableBadges(), Printer::factory()->badge()->create());

    Queue::assertPushed(
        PrepareBadgePrintBatchJob::class,
        fn (PrepareBadgePrintBatchJob $job) => $job->connection === config('queue.long_running')
    );
});

it('makes the run ready once the job has prepared it', function () {
    $badges = preparableBadges(2);

    // No Queue::fake(): the suite runs on the sync driver, so the job has already run.
    $batch = BadgePrintQueue::queue($badges, Printer::factory()->badge()->create());

    expect($batch->status)->toBe(PrintBatchStatusEnum::Ready)
        ->and($batch->printJobs()->count())->toBe(2)
        ->and($batch->total_jobs)->toBe(2)
        ->and(PrintBatch::selectable()->pluck('id'))->toContain($batch->id);

    $badges->each(fn (Badge $badge) => expect($badge->fresh()->status_fulfillment)->toBeInstanceOf(Processing::class));
});

it('names the run after the attendee range it could not know when it was opened', function () {
    $badges = preparableBadges(2);

    $batch = BadgePrintQueue::queue($badges, Printer::factory()->badge()->create());

    // The ids exist only after the transition, which happens in the job, so a name with a
    // range in it is proof the rename ran.
    expect($batch->name)->toContain('2 badges')
        ->and($batch->name)->toContain('-');
});

it('keeps an explicit name rather than renaming it after preparation', function () {
    $batch = BadgePrintQueue::queue(
        preparableBadges(2),
        Printer::factory()->badge()->create(),
        name: 'Friday morning',
    );

    expect($batch->name)->toBe('Friday morning');
});

it('puts the badges back and cancels the run when the artwork cannot be rendered', function () {
    $badge = unrenderableBadge();

    Storage::fake();

    $batch = PrintBatch::open(name: 'doomed', printer: Printer::factory()->badge()->create(), expectedJobs: 1);

    expect(fn () => BadgePrintQueue::prepare($batch, [$badge->id]))->toThrow(RuntimeException::class);

    $batch->refresh();
    $badge->refresh();

    // The run is gone, and it says why rather than sitting Draft forever.
    expect($batch->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($batch->pause_reason)->not->toBeNull()
        ->and($batch->printJobs()->count())->toBe(0)
        // This is the bug: the badge must not be left in Processing with no card coming.
        ->and($badge->status_fulfillment)->toBeInstanceOf(Pending::class)
        ->and($badge->printing_locked_at)->toBeNull();
});

it('keeps the custom_id it allocated when it puts a badge back', function () {
    $badge = unrenderableBadge();

    Storage::fake();

    $batch = PrintBatch::open(name: 'doomed', expectedJobs: 1);

    expect(fn () => BadgePrintQueue::prepare($batch, [$badge->id]))->toThrow(RuntimeException::class);

    // Allocated once and re-used by the next attempt. Clearing it would hand the same
    // number to somebody else.
    expect($badge->fresh()->custom_id)->not->toBeNull();
});

it('returns a badge that has printed before rather than stranding it', function () {
    // Found end to end against a copy of production: a badge whose card came out at some
    // earlier point still carries that printed job. Guarding the compensation on "has any
    // print job" therefore skipped it, and a failed preparation left every badge with
    // history sitting in Processing with no run - the exact orphan this undo exists for.
    $badge = unrenderableBadge();

    PrintJob::factory()->create([
        'printable_type' => $badge->getMorphClass(),
        'printable_id' => $badge->id,
        'type' => PrintJobTypeEnum::Badge,
        'status' => PrintJobStatusEnum::Printed,
        'printed_at' => now()->subDay(),
    ]);

    Storage::fake();

    $batch = PrintBatch::open(name: 'doomed', expectedJobs: 1);

    expect(fn () => BadgePrintQueue::prepare($batch, [$badge->id]))->toThrow(RuntimeException::class);

    expect($badge->fresh()->status_fulfillment)->toBeInstanceOf(Pending::class);
});

it('leaves a badge alone when another run has a card on its way for it', function () {
    // The one job history that does mean hands off: something claimable exists, so the
    // badge belongs to that run and not to this failed one.
    $badge = unrenderableBadge();

    PrintJob::factory()->create([
        'printable_type' => $badge->getMorphClass(),
        'printable_id' => $badge->id,
        'type' => PrintJobTypeEnum::Badge,
        'status' => PrintJobStatusEnum::Pending,
    ]);

    Storage::fake();

    $batch = PrintBatch::open(name: 'doomed', expectedJobs: 1);

    // The badge is dropped before anything is moved, so nothing throws and nothing is
    // left half done either.
    BadgePrintQueue::prepare($batch, [$badge->id]);

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($badge->fresh()->status_fulfillment)->toBeInstanceOf(Pending::class);
});

it('does not return a badge that was already in processing before the run', function () {
    // A reprint: the badge is in Processing because an earlier run put it there, and it
    // is in this selection alongside one that cannot be rendered.
    $reprint = Badge::factory()->create([
        'status_fulfillment' => Processing::$name,
        'custom_id' => '9001-1',
    ]);

    $doomed = unrenderableBadge();

    Storage::fake();

    $batch = PrintBatch::open(name: 'doomed', expectedJobs: 2);

    expect(fn () => BadgePrintQueue::prepare($batch, [$reprint->id, $doomed->id]))
        ->toThrow(RuntimeException::class);

    expect($reprint->fresh()->status_fulfillment)->toBeInstanceOf(Processing::class);
});

it('puts the badges back when the worker is killed rather than throwing', function () {
    // The timeout case: handle() never returns, so prepare()'s own catch never runs and
    // failed() is the only thing left to undo the move.
    $badge = preparableBadges()->first();

    $batch = PrintBatch::open(name: 'killed', expectedJobs: 1);

    // Everything prepare() does up to the render, which is what a killed worker leaves
    // behind: the badge moved, nothing committed.
    $badge->status_fulfillment->transitionTo(Processing::class);
    cache()->put("print-batch:{$batch->id}:moved-to-processing", [$badge->id], now()->addDay());

    (new PrepareBadgePrintBatchJob($batch, [$badge->id]))->failed(new RuntimeException('worker killed'));

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($badge->fresh()->status_fulfillment)->toBeInstanceOf(Pending::class);
});

it('leaves a committed run alone when the job fails after committing it', function () {
    // A failure after the jobs exist must never cancel them: the cards are real and
    // queued, and an agent may already be printing one.
    $badge = preparableBadges()->first();

    $batch = BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create());

    (new PrepareBadgePrintBatchJob($batch, [$badge->id]))->failed(new RuntimeException('late failure'));

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Ready)
        ->and($batch->printJobs()->count())->toBe(1)
        ->and($badge->fresh()->status_fulfillment)->toBeInstanceOf(Processing::class);
});

it('does not prepare a run somebody cancelled while it sat in the queue', function () {
    $badge = preparableBadges()->first();

    $batch = PrintBatch::open(name: 'cancelled', expectedJobs: 1);
    $batch->transitionTo(PrintBatchStatusEnum::Cancelled);

    BadgePrintQueue::prepare($batch, [$badge->id]);

    expect($batch->fresh()->printJobs()->count())->toBe(0)
        ->and($badge->fresh()->status_fulfillment)->toBeInstanceOf(Pending::class);
});

it('cancels the run when every badge in it was taken by another run first', function () {
    $badge = preparableBadges()->first();

    // Another run already holds a card for it, which is what withoutCardsAlreadyOnTheirWay
    // drops. By the time this job runs there is nothing left to print.
    $other = BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create());

    $batch = PrintBatch::open(name: 'too late', expectedJobs: 1);

    BadgePrintQueue::prepare($batch, [$badge->id]);

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($batch->fresh()->printJobs()->count())->toBe(0)
        // The other run's card is untouched.
        ->and(PrintJob::where('print_batch_id', $other->id)->count())->toBe(1);
});
