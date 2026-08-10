<?php

/*
 * Retrying a run whose preparation failed.
 *
 * A preparation that dies - a render that threw, a worker killed mid-run - cancels its batch
 * and hands every badge back to Pending. What is left is a cancelled run holding no cards,
 * and until now the only way on was to find the same attendees in the badge list and select
 * them by hand, which for a run of a hundred cards is how badges get missed.
 *
 * So the selection is written on the batch when it is opened, before any of the expensive
 * work, and Retry sends it through the same queue again as a new batch. Two things this must
 * never become: a way to reprint a run that actually printed, and a way to get two live runs
 * holding the same card.
 */

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Services\BadgePrintQueue;
use App\Enum\PrintBatchStatusEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\EventUser;
use App\Models\Fursuit\States\Rejected;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/** Where App\Support\Manage\Toast writes. */
const PRINT_BATCH_RETRY_TOAST = 'inertia.flash_data.toast.title';

/**
 * Badges that can be sent to a printer: artwork already rendered, custom_id already
 * allocated, and an event registration so the move to Processing has one to read.
 */
function retryableBadges(int $count = 1): Collection
{
    static $next = 7000;

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

/**
 * The shape a failed preparation leaves behind: a cancelled batch carrying its reason and
 * the badges it was asked for, with no jobs and the badges back in Pending.
 *
 * Built through `open()` and `abandon()` rather than by breaking a render, because what is
 * under test is what happens *after* the failure. The real failure path is covered end to
 * end below and in BadgePrintPreparationTest.
 */
function failedPreparation(Collection $badges, ?Printer $printer = null): PrintBatch
{
    $batch = PrintBatch::open(
        name: 'Friday 14:00',
        printer: $printer ?? Printer::factory()->badge()->create(),
        expectedJobs: $badges->count(),
        requestedBadgeIds: $badges->map(fn (Badge $badge) => (int) $badge->id)->all(),
    );

    BadgePrintQueue::abandon($batch, [], 'The print run could not be prepared.');

    return $batch->fresh();
}

beforeEach(function () {
    Storage::fake('s3');

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
});

it('records what a run was asked to print before it can fail', function () {
    $badges = retryableBadges(2);

    $batch = BadgePrintQueue::queue($badges, Printer::factory()->badge()->create());

    expect($batch->requested_badge_ids)->toBe($badges->map(fn (Badge $b) => (int) $b->id)->all());
});

it('keeps the selection on a run that died mid-render', function () {
    // The real failure path: a badge with no image behind it cannot be rendered, so
    // preparation throws and undoes itself. The selection has to survive that, because it
    // is the only record of what the operator asked for.
    $badge = Badge::factory()->create(['status_fulfillment' => Pending::$name]);

    EventUser::factory()->create([
        'user_id' => $badge->fursuit->user_id,
        'event_id' => $badge->fursuit->event_id,
    ]);

    Storage::fake();

    expect(fn () => BadgePrintQueue::queue(collect([$badge]), Printer::factory()->badge()->create()))
        ->toThrow(RuntimeException::class);

    $batch = PrintBatch::latest('id')->first();

    expect($batch->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($batch->printJobs()->count())->toBe(0)
        ->and($batch->requested_badge_ids)->toBe([(int) $badge->id])
        ->and($batch->preparationFailed())->toBeTrue();
});

it('queues the same badges again as a new run linked to the failed one', function () {
    $badges = retryableBadges(2);
    $printer = Printer::factory()->badge()->create();
    $failed = failedPreparation($badges, $printer);

    actingAs($this->admin)->post(route('admin.print-batches.retry', $failed));

    $retry = PrintBatch::where('retry_of_batch_id', $failed->id)->sole();

    expect($retry->status)->toBe(PrintBatchStatusEnum::Ready)
        ->and($retry->printer_id)->toBe($printer->id)
        ->and($retry->printJobs()->pluck('printable_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe($badges->map(fn (Badge $b) => (int) $b->id)->sort()->values()->all())
        // The run that failed is the record that it failed. Cancelled is terminal.
        ->and($failed->fresh()->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($failed->fresh()->printJobs()->count())->toBe(0);
});

it('sends the operator to the run it just queued', function () {
    $failed = failedPreparation(retryableBadges());

    $retry = actingAs($this->admin)->post(route('admin.print-batches.retry', $failed));

    $queued = PrintBatch::where('retry_of_batch_id', $failed->id)->sole();

    $retry->assertRedirect(route('admin.print-batches.show', $queued))
        ->assertSessionHas(PRINT_BATCH_RETRY_TOAST, 'Batch queued again');
});

it('keeps the desk clerk who queued the original on the run that replaces it', function () {
    // The clerk is the one standing at the counter waiting for the card, so the retry has
    // to reach their own print list and their dashboard rather than disappearing into the
    // admin's.
    $staff = Staff::factory()->create();

    $badges = retryableBadges();

    $failed = PrintBatch::open(
        name: 'Desk run',
        printer: Printer::factory()->badge()->create(),
        createdByStaffId: $staff->id,
        expectedJobs: 1,
        requestedBadgeIds: [(int) $badges->first()->id],
    );

    BadgePrintQueue::abandon($failed, [], 'The print run could not be prepared.');

    actingAs($this->admin)->post(route('admin.print-batches.retry', $failed));

    $retry = PrintBatch::where('retry_of_batch_id', $failed->id)->sole();

    expect($retry->created_by_staff_id)->toBe($staff->id)
        ->and($retry->created_by_id)->toBe($this->admin->id);
});

it('refuses a run that was cancelled after it had cards', function () {
    // A cancelled *run* is not a failed preparation: it holds jobs, and some of them may
    // have printed. Repeating it wholesale would put duplicate cards in the pickup bins.
    $batch = PrintBatch::build('Friday 14:00', retryableBadges(2), Printer::factory()->badge()->create());
    $batch->cancel('Jam');

    actingAs($this->admin)->post(route('admin.print-batches.retry', $batch))
        ->assertSessionHas(PRINT_BATCH_RETRY_TOAST, 'Nothing was queued');

    expect(PrintBatch::where('retry_of_batch_id', $batch->id)->count())->toBe(0);
});

it('refuses a run that is still printing', function () {
    $batch = PrintBatch::factory()->printing()->create();

    actingAs($this->admin)->post(route('admin.print-batches.retry', $batch))
        ->assertSessionHas(PRINT_BATCH_RETRY_TOAST, 'Nothing was queued');

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Printing)
        ->and(PrintBatch::where('retry_of_batch_id', $batch->id)->count())->toBe(0);
});

it('refuses a second retry while the first is still live', function () {
    // Pressed twice, or by two operators. A retry that is still a Draft holds no jobs yet,
    // so nothing downstream would recognise the badges as already queued and both runs
    // would render the same cards.
    $failed = failedPreparation(retryableBadges());

    actingAs($this->admin)->post(route('admin.print-batches.retry', $failed));
    actingAs($this->admin)->post(route('admin.print-batches.retry', $failed))
        ->assertSessionHas(PRINT_BATCH_RETRY_TOAST, 'Nothing was queued');

    expect(PrintBatch::where('retry_of_batch_id', $failed->id)->count())->toBe(1);
});

it('queues nothing when the badges may not be printed any more', function () {
    // The selection is re-filtered on the way through, which is the point of going back
    // through the queue: a fursuit rejected since the run failed does not get a card.
    $badge = retryableBadges()->first();
    $failed = failedPreparation(collect([$badge]));

    $badge->fursuit->forceFill(['status' => Rejected::$name])->saveQuietly();

    actingAs($this->admin)->post(route('admin.print-batches.retry', $failed))
        ->assertSessionHas(PRINT_BATCH_RETRY_TOAST, 'Nothing was queued');

    expect(PrintBatch::where('retry_of_batch_id', $failed->id)->count())->toBe(0);
});

it('is refused to a reviewer', function () {
    $failed = failedPreparation(retryableBadges());

    actingAs($this->reviewer);

    post(route('admin.print-batches.retry', $failed))->assertForbidden();

    expect(PrintBatch::where('retry_of_batch_id', $failed->id)->count())->toBe(0);
});
