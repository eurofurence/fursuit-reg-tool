<?php

/*
 * Print batches, phase 7 (plan part 4). Transcribed from audit 4.8 and 4.8.1.
 *
 * A batch is a live convention print run, so four groups of cases carry more weight than
 * the parity transcription:
 *
 *  - nothing acts on its own. Opening the list, opening a batch and the ten-second polls
 *    behind both must not move a batch, a card, a printer or a badge.
 *  - the three run controls require is_admin. There is no PrintBatchPolicy today, so a
 *    reviewer can halt or cancel a run in progress (audit 51, plan 2.10 #18).
 *  - a refused control writes nothing. PrintBatch::resume() requeues the failed cards
 *    before it attempts the transition and PrintBatch::cancel() cancels the outstanding
 *    jobs inside its transaction before it attempts its own, so "call it and read the
 *    return value" is not a safe shape for either.
 *  - batches are immutable. No create, no store, no edit, no update, no destroy, no bulk.
 *
 * The rest is parity: eleven columns in order, three filters, eight card columns, two card
 * filters, and every confirm string word for word.
 */

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/** The audit's eleven columns, in order (checklist 13, Columns). */
const MANAGE_PRINT_BATCH_COLUMNS = [
    'id',
    'name',
    'status',
    'printer_name',
    'event_name',
    'progress',
    'unverified',
    'pause_reason',
    'created_by_name',
    'started_at',
    'completed_at',
];

/** The relation manager's eight columns, in order (checklist 14). */
const MANAGE_PRINT_BATCH_CARD_COLUMNS = [
    'sequence',
    'badge',
    'fursuit',
    'status',
    'completion_source',
    'verified_print_at',
    'attempt_count',
    'error_message',
];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_PRINT_BATCH_TOAST = 'inertia.flash_data.toast.title';

beforeEach(function () {
    // Badge::factory reaches the fursuit image path through the s3 disk.
    Storage::fake('s3');

    // ManageEventScope runs on every /admin request whether or not the page is scoped, and
    // this list deliberately is not (plan 2.9).
    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->attendee = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->printer = Printer::factory()->badge()->create(['name' => 'Zebra 1']);

    $this->badge = function (array $attributes = []) {
        $owner = User::factory()->create();

        return Badge::factory()->create([
            'status_fulfillment' => 'pending',
            'status_payment' => 'paid',
            'fursuit_id' => Fursuit::factory()->create([
                'event_id' => $this->event->id,
                'user_id' => $owner->id,
                'species_id' => Species::factory()->create()->id,
            ])->id,
            ...$attributes,
        ]);
    };

    $this->batch = fn (array $attributes = []) => PrintBatch::factory()->create([
        'name' => 'Batch 1000-1099',
        'printer_id' => $this->printer->id,
        'event_id' => $this->event->id,
        ...$attributes,
    ]);

    $this->card = function (PrintBatch $batch, array $attributes = []) {
        return PrintJob::factory()->create([
            'print_batch_id' => $batch->id,
            'printer_id' => $this->printer->id,
            'printable_type' => Badge::class,
            'printable_id' => ($this->badge)()->id,
            'type' => PrintJobTypeEnum::Badge,
            'status' => PrintJobStatusEnum::Pending,
            'sequence' => 1,
            ...$attributes,
        ]);
    };

    $this->props = fn (array $query = []) => actingAs($this->admin)
        ->get(route('admin.print-batches.index', $query))
        ->viewData('page')['props'];

    $this->rowActions = fn (array $actions, string $name) => collect($actions)->firstWhere('name', $name);
});

/*
 * Access. PrintBatchPolicy is is_admin throughout. The four verbs were is_admin from the
 * rebuild; reading was access-manage until reviewers were narrowed to Dashboard, Badges
 * and Fursuits, which took the live print run off a screen that could not act on it. See
 * docs/admin/roles.md.
 */

test('a guest is redirected to login', function () {
    get(route('admin.print-batches.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the batch list at all', function () {
    actingAs($this->attendee)->get(route('admin.print-batches.index'))->assertForbidden();
});

test('a reviewer cannot read the batch list or a batch', function () {
    $batch = ($this->batch)();

    actingAs($this->reviewer)->get(route('admin.print-batches.index'))->assertForbidden();
    actingAs($this->reviewer)->get(route('admin.print-batches.show', $batch))->assertForbidden();
});

test('a reviewer is refused pause, resume, cancel and verify', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);
    $card = ($this->card)($batch, ['status' => PrintJobStatusEnum::Printed]);

    actingAs($this->reviewer);

    post(route('admin.print-batches.pause', $batch), ['reason' => 'Jam'])->assertForbidden();
    post(route('admin.print-batches.resume', $batch))->assertForbidden();
    post(route('admin.print-batches.cancel', $batch), ['reason' => 'No'])->assertForbidden();
    post(route('admin.print-batches.jobs.verify', [$batch, $card]))->assertForbidden();

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Printing)
        ->and($card->fresh()->verified_print_at)->toBeNull();
});

/* Columns. */

test('the list renders the eleven columns in order, with their labels and types', function () {
    ($this->batch)();

    actingAs($this->admin)->get(route('admin.print-batches.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/PrintBatches/Index')
            ->where('columns.0', fn ($c) => $c['key'] === 'id' && $c['label'] === 'ID' && $c['sortable'])
            ->where('columns.1', fn ($c) => $c['key'] === 'name' && $c['label'] === 'Name' && $c['sortable'])
            ->where('columns.2', fn ($c) => $c['key'] === 'status' && $c['label'] === 'Status' && $c['type'] === 'badge')
            ->where('columns.3', fn ($c) => $c['key'] === 'printer_name' && $c['label'] === 'Printer' && $c['sortable'] && $c['fallback'] === 'Unassigned')
            ->where('columns.4', fn ($c) => $c['key'] === 'event_name' && $c['label'] === 'Event' && $c['toggleable'] && $c['hiddenByDefault'] && $c['fallback'] === 'None')
            ->where('columns.5', fn ($c) => $c['key'] === 'progress' && $c['label'] === 'Progress' && $c['type'] === 'badge')
            ->where('columns.6', fn ($c) => $c['key'] === 'unverified' && $c['label'] === 'Needs check' && $c['type'] === 'badge' && $c['align'] === 'center')
            ->where('columns.7', fn ($c) => $c['key'] === 'pause_reason' && $c['label'] === 'Reason' && $c['fallback'] === 'None')
            ->where('columns.8', fn ($c) => $c['key'] === 'created_by_name' && $c['label'] === 'Built by' && $c['toggleable'] && $c['hiddenByDefault'] && $c['fallback'] === 'System')
            ->where('columns.9', fn ($c) => $c['key'] === 'started_at' && $c['label'] === 'Started' && $c['sortable'] && $c['fallback'] === 'Not started')
            ->where('columns.10', fn ($c) => $c['key'] === 'completed_at' && $c['label'] === 'Completed' && $c['toggleable'] && $c['hiddenByDefault'] && $c['fallback'] === 'Not finished')
            ->count('columns', 11)
        );

    expect(array_column(($this->props)()['columns'], 'key'))->toBe(MANAGE_PRINT_BATCH_COLUMNS);
});

test('the three toggleable columns open hidden', function () {
    ($this->batch)();

    expect(($this->props)()['hiddenColumns'])->toBe(['event_name', 'created_by_name', 'completed_at']);
});

test('the status column speaks the enum vocabulary', function () {
    ($this->batch)(['status' => PrintBatchStatusEnum::Ready]);

    expect(($this->props)()['rows'][0]['cells']['status'])
        ->toMatchArray(['label' => 'Ready to print', 'tone' => 'info']);
});

test('all six batch states carry a tone of their own', function () {
    // This is the column an operator reads while a printer is jammed, so the six states
    // have to stay six colours. Sharing one put a halted run on the same tone as a run in
    // progress, and a killed run on the same tone as one that never started.
    $tone = function (PrintBatchStatusEnum $status) {
        PrintBatch::query()->delete();
        ($this->batch)(['status' => $status]);

        return ($this->props)()['rows'][0]['cells']['status'];
    };

    $tones = collect(PrintBatchStatusEnum::cases())
        ->mapWithKeys(fn (PrintBatchStatusEnum $case) => [$case->value => $tone($case)['tone']]);

    expect($tones->all())->toBe([
        'draft' => 'idle',
        'ready' => 'info',
        'printing' => 'live',
        'paused' => 'warn',
        'completed' => 'ok',
        'cancelled' => 'danger',
    ])
        ->and($tones->unique()->count())->toBe(6);
});

test('the progress badge carries the counts, the verified and failed line, and the colour ladder', function () {
    $cells = function (int $total, int $printed, int $verified, int $failed) {
        PrintBatch::query()->delete();

        ($this->batch)([
            'total_jobs' => $total,
            'printed_count' => $printed,
            'verified_count' => $verified,
            'failed_count' => $failed,
        ]);

        return ($this->props)()['rows'][0]['cells'];
    };

    expect($cells(10, 4, 2, 0)['progress'])
        ->toMatchArray(['label' => '4 / 10', 'tone' => 'info', 'description' => '2 verified, 0 failed']);

    // Any failure is danger, even on a finished run.
    expect($cells(10, 10, 10, 1)['progress'])->toMatchArray(['label' => '10 / 10', 'tone' => 'danger']);

    expect($cells(10, 10, 9, 0)['progress'])->toMatchArray(['label' => '10 / 10', 'tone' => 'ok']);

    // An empty batch is not "complete": total_jobs > 0 is part of the resource's own test.
    expect($cells(0, 0, 0, 0)['progress'])->toMatchArray(['label' => '0 / 0', 'tone' => 'info']);
});

test('the needs-check badge is printed minus verified, warning above zero', function () {
    $cell = function (int $printed, int $verified) {
        PrintBatch::query()->delete();

        ($this->batch)(['total_jobs' => 10, 'printed_count' => $printed, 'verified_count' => $verified]);

        return ($this->props)()['rows'][0]['cells']['unverified'];
    };

    expect($cell(6, 2))->toMatchArray(['label' => '4', 'tone' => 'warn'])
        ->and($cell(6, 6))->toMatchArray(['label' => '0', 'tone' => 'idle'])
        // audit 80: verified_count counts any job carrying a verified_print_at, cancelled
        // ones included, so the subtraction is not bounded below. It is shown rather than
        // clamped, and it reads idle.
        ->and($cell(1, 3))->toMatchArray(['label' => '-2', 'tone' => 'idle']);
});

test('the reason column truncates at forty characters and keeps the full text as the tooltip', function () {
    $reason = str_repeat('Card jam on the left feed roller ', 3);

    ($this->batch)(['status' => PrintBatchStatusEnum::Paused, 'pause_reason' => $reason]);

    $cell = ($this->props)()['rows'][0]['cells']['pause_reason'];

    expect($cell['title'])->toBe($reason)
        ->and($cell['display'])->toBe(Str::limit($reason, 40))
        ->and($cell['display'])->not->toBe($reason);
});

test('the two timestamps render in the M j, H:i format the resource declares', function () {
    $started = now()->subHours(2);

    ($this->batch)(['status' => PrintBatchStatusEnum::Printing, 'started_at' => $started]);

    $cells = ($this->props)()['rows'][0]['cells'];

    expect($cells['started_at']['display'])->toBe($started->format('M j, H:i'))
        ->and($cells['completed_at'])->toBeNull();
});

/* Filters and sort. */

test('the list declares the three audit filters', function () {
    ($this->batch)();

    $filters = ($this->props)()['filters'];

    expect(array_column($filters, 'key'))->toBe(['status', 'printer', 'needs_verification'])
        ->and($filters[0]['multiple'])->toBeTrue()
        ->and(collect($filters[0]['options'])->pluck('label')->all())->toBe([
            'Draft', 'Ready to print', 'Printing', 'Paused', 'Completed', 'Cancelled',
        ])
        ->and($filters[1]['type'])->toBe('select')
        ->and($filters[2])->toMatchArray(['type' => 'boolean', 'label' => 'Has unverified cards']);
});

test('the status filter takes several values at once', function () {
    ($this->batch)(['status' => PrintBatchStatusEnum::Draft]);
    ($this->batch)(['status' => PrintBatchStatusEnum::Paused]);
    ($this->batch)(['status' => PrintBatchStatusEnum::Completed]);

    $rows = ($this->props)(['filter' => ['status' => ['draft', 'paused']]])['rows'];

    expect($rows)->toHaveCount(2);
});

test('the printer filter narrows to one printer', function () {
    $other = Printer::factory()->badge()->create(['name' => 'Zebra 2']);

    ($this->batch)();
    ($this->batch)(['printer_id' => $other->id]);

    expect(($this->props)(['filter' => ['printer' => $other->id]])['rows'])->toHaveCount(1);
});

test('the needs-verification toggle finds the batches holding a printed but unverified card', function () {
    $waiting = ($this->batch)();
    ($this->card)($waiting, ['status' => PrintJobStatusEnum::Printed]);

    $checked = ($this->batch)();
    ($this->card)($checked, [
        'status' => PrintJobStatusEnum::Printed,
        'verified_print_at' => now(),
        'verification_source' => PrintVerificationSourceEnum::Operator,
    ]);

    $untouched = ($this->batch)();
    ($this->card)($untouched);

    expect(($this->props)()['rows'])->toHaveCount(3);

    $rows = ($this->props)(['filter' => ['needs_verification' => 1]])['rows'];

    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($waiting->id);
});

test('the list opens newest first', function () {
    $first = ($this->batch)();
    $second = ($this->batch)();

    $props = ($this->props)();

    expect($props['sort'])->toBe(['key' => 'id', 'dir' => 'desc'])
        ->and($props['rows'][0]['id'])->toBe($second->id)
        ->and($props['rows'][1]['id'])->toBe($first->id);
});

test('the list searches the name and the printer', function () {
    ($this->batch)(['name' => 'Morning run']);
    ($this->batch)(['name' => 'Evening run', 'printer_id' => Printer::factory()->badge()->create(['name' => 'Fargo 9'])->id]);

    expect(($this->props)(['search' => 'Morning'])['rows'])->toHaveCount(1)
        ->and(($this->props)(['search' => 'Fargo'])['rows'][0]['cells']['name'])->toBe('Evening run');
});

/* Immutability. */

test('the list offers no create button, no bulk action and no row delete', function () {
    ($this->batch)();

    $props = ($this->props)();

    expect($props['pageActions'])->toBe([])
        ->and($props['bulkActions'])->toBe([])
        ->and(array_column($props['rows'][0]['actions'], 'name'))->not->toContain('delete');
});

test('no create, store, edit, update or destroy route exists for a batch', function () {
    foreach (['create', 'store', 'edit', 'update', 'destroy', 'bulk.destroy'] as $name) {
        expect(Route::has("admin.print-batches.{$name}"))->toBeFalse();
    }
});

/* The three run controls: copy, visibility and disabled reasons. */

test('pause carries its form field, its helper text and its confirm copy verbatim', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);

    $pause = ($this->rowActions)(($this->props)()['rows'][0]['actions'], 'pause');

    expect($pause)->toMatchArray([
        'label' => 'Pause',
        'method' => 'post',
        'icon' => 'pause',
        'tone' => 'warn',
        'url' => route('admin.print-batches.pause', $batch),
        'disabledReason' => null,
    ])
        ->and($pause['confirm'])->toMatchArray(['heading' => 'Pause', 'submit' => 'Confirm'])
        ->and($pause['fields'][0])->toMatchArray([
            'key' => 'reason',
            'label' => 'Why is it being paused?',
            'required' => true,
            'maxLength' => 1000,
            'helper' => 'Shown to whoever is standing at the printer.',
        ]);
});

test('resume carries the confirm description verbatim, under the default heading', function () {
    ($this->batch)(['status' => PrintBatchStatusEnum::Paused]);

    $resume = ($this->rowActions)(($this->props)()['rows'][0]['actions'], 'resume');

    expect($resume)->toMatchArray(['label' => 'Resume', 'icon' => 'play', 'tone' => 'ok', 'disabledReason' => null])
        ->and($resume['confirm'])->toBe([
            'heading' => 'Resume',
            'description' => 'Only resume once the fault at the printer has actually been dealt with.',
            'submit' => 'Confirm',
        ]);
});

test('cancel carries its heading, its description and its default reason verbatim', function () {
    ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);

    $cancel = ($this->rowActions)(($this->props)()['rows'][0]['actions'], 'cancel');

    expect($cancel)->toMatchArray(['label' => 'Cancel', 'icon' => 'circle-x', 'tone' => 'danger', 'disabledReason' => null])
        ->and($cancel['confirm'])->toBe([
            'heading' => 'Cancel this batch',
            'description' => 'Cards already printed stay printed. Everything still queued is cancelled, and attendees whose card never printed get their badge back to edit.',
            'submit' => 'Confirm',
        ])
        ->and($cancel['fields'][0])->toMatchArray([
            'key' => 'reason',
            'label' => 'Reason',
            'required' => false,
            'maxLength' => 1000,
            'default' => 'Cancelled from the admin panel',
        ]);
});

test('a control that cannot fire is offered disabled with the reason rather than hidden', function () {
    // plan 2.5: the Filament panel hid actions, which leaves an operator staring at a
    // paused run wondering where Resume went. PrinterController's clear-error does the
    // same thing.
    ($this->batch)(['status' => PrintBatchStatusEnum::Draft]);

    $actions = ($this->props)()['rows'][0]['actions'];

    expect(array_column($actions, 'name'))->toBe(['view', 'pause', 'resume', 'cancel'])
        ->and(($this->rowActions)($actions, 'pause')['disabledReason'])->toContain('Only a batch that is printing can be paused')
        ->and(($this->rowActions)($actions, 'resume')['disabledReason'])->toContain('Only a paused batch can be resumed')
        // Draft is not terminal, so cancel is live.
        ->and(($this->rowActions)($actions, 'cancel')['disabledReason'])->toBeNull();
});

test('a terminal batch offers no live control at all', function () {
    ($this->batch)(['status' => PrintBatchStatusEnum::Completed]);

    $actions = ($this->props)()['rows'][0]['actions'];

    expect(($this->rowActions)($actions, 'pause')['disabledReason'])->not->toBeNull()
        ->and(($this->rowActions)($actions, 'resume')['disabledReason'])->not->toBeNull()
        ->and(($this->rowActions)($actions, 'cancel')['disabledReason'])->toContain('There is nothing left to cancel');
});

/* Pause. */

test('pausing a printing batch stores the reason and flashes Batch paused', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing, 'started_at' => now()]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.pause', $batch), ['reason' => 'Ribbon out'])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Batch paused');

    expect($batch->fresh())
        ->status->toBe(PrintBatchStatusEnum::Paused)
        ->pause_reason->toBe('Ribbon out');
});

test('pause requires a reason of at most a thousand characters', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.pause', $batch), [])
        ->assertSessionHasErrors('reason');

    actingAs($this->admin)
        ->post(route('admin.print-batches.pause', $batch), ['reason' => str_repeat('x', 1001)])
        ->assertSessionHasErrors('reason');

    // A refused validation is not allowed to have moved anything either.
    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Printing);
});

test('pausing a batch that is not printing changes nothing and says so', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Paused, 'pause_reason' => 'Card jam']);

    actingAs($this->admin)
        ->post(route('admin.print-batches.pause', $batch), ['reason' => 'Something else'])
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Cannot pause a batch that is Paused');

    expect($batch->fresh()->pause_reason)->toBe('Card jam');
});

/* Resume. */

test('resuming a paused batch restarts it and puts its failed cards back in the queue', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Paused, 'pause_reason' => 'Card jam']);
    $failed = ($this->card)($batch, ['status' => PrintJobStatusEnum::Failed, 'error_message' => 'Jam']);

    actingAs($this->admin)
        ->post(route('admin.print-batches.resume', $batch))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Batch resumed');

    expect($batch->fresh())
        ->status->toBe(PrintBatchStatusEnum::Printing)
        ->pause_reason->toBeNull()
        ->and($failed->fresh()->status)->toBe(PrintJobStatusEnum::Pending);
});

test('a refused resume does not requeue the failed cards on its way out', function () {
    /*
     * PrintBatch::resume() calls requeueFailedJobs() before it attempts the transition, so
     * a resume that is going to be refused would still rescue every failed card if the
     * controller called it speculatively and read the return value.
     */
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);
    $failed = ($this->card)($batch, ['status' => PrintJobStatusEnum::Failed, 'error_message' => 'Jam']);

    actingAs($this->admin)
        ->post(route('admin.print-batches.resume', $batch))
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Cannot resume a batch that is Printing');

    expect($failed->fresh()->status)->toBe(PrintJobStatusEnum::Failed);
});

/* Cancel. */

test('cancelling stops the run, unlocks exactly the badges with no printed card and recalculates the counters', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing, 'total_jobs' => 2]);

    $printedBadge = ($this->badge)(['printing_locked_at' => now()]);
    $queuedBadge = ($this->badge)(['printing_locked_at' => now()]);

    $printed = ($this->card)($batch, [
        'printable_id' => $printedBadge->id,
        'status' => PrintJobStatusEnum::Printed,
        'sequence' => 1,
    ]);
    $queued = ($this->card)($batch, [
        'printable_id' => $queuedBadge->id,
        'status' => PrintJobStatusEnum::Pending,
        'sequence' => 2,
    ]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.cancel', $batch), ['reason' => 'Wrong artwork'])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Batch cancelled');

    $batch->refresh();

    expect($batch->status)->toBe(PrintBatchStatusEnum::Cancelled)
        ->and($batch->pause_reason)->toBe('Wrong artwork')
        ->and($batch->total_jobs)->toBe(2)
        ->and($batch->printed_count)->toBe(1)
        // The printed card stays printed; only the queued one is cancelled.
        ->and($printed->fresh()->status)->toBe(PrintJobStatusEnum::Printed)
        ->and($queued->fresh()->status)->toBe(PrintJobStatusEnum::Cancelled)
        // The attendee whose card never printed gets their badge back to edit.
        ->and($queuedBadge->fresh()->printing_locked_at)->toBeNull()
        ->and($printedBadge->fresh()->printing_locked_at)->not->toBeNull();
});

test('an empty cancel reason falls back to the admin-panel default', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.cancel', $batch), ['reason' => ''])
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Batch cancelled');

    expect($batch->fresh()->pause_reason)->toBe('Cancelled from the admin panel');
});

test('cancelling a terminal batch cancels nothing and carries the resource copy verbatim', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Completed]);
    $card = ($this->card)($batch, ['status' => PrintJobStatusEnum::Pending]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.cancel', $batch), ['reason' => 'Too late'])
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Cannot cancel a batch that is Completed');

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Completed)
        ->and($batch->fresh()->pause_reason)->toBeNull()
        // PrintBatch::cancel() writes inside its transaction before it attempts the
        // transition, so a speculative call would have cancelled this card first.
        ->and($card->fresh()->status)->toBe(PrintJobStatusEnum::Pending);
});

/* The detail page and its card list. */

test('the detail page carries the three infolist sections', function () {
    $creator = User::factory()->create(['name' => 'Ops Lead']);

    $batch = ($this->batch)([
        'status' => PrintBatchStatusEnum::Paused,
        'pause_reason' => 'Card jam',
        'created_by_id' => $creator->id,
        'total_jobs' => 4,
        'printed_count' => 3,
        'verified_count' => 2,
        'failed_count' => 1,
        'started_at' => now()->subHour(),
    ]);

    actingAs($this->admin)->get(route('admin.print-batches.show', $batch))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/PrintBatches/Show')
            ->where('batch.name', 'Batch 1000-1099')
            ->where('batch.status.label', 'Paused')
            ->where('batch.printer', 'Zebra 1')
            ->where('batch.event', 'Eurofurence 29')
            ->where('batch.createdBy', 'Ops Lead')
            ->where('batch.pauseReason', 'Card jam')
            ->where('batch.progress', ['total' => 4, 'printed' => 3, 'verified' => 2, 'failed' => 1])
            ->where('batch.timing.completed', null)
            ->etc()
        );
});

test('the run controls are reachable from the detail page, not only from the row', function () {
    // audit 84: ViewPrintBatch::getHeaderActions() returns [] deliberately, so pause was
    // unreachable from the page an operator opens to see which card jammed.
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);

    $props = actingAs($this->admin)
        ->get(route('admin.print-batches.show', $batch))
        ->viewData('page')['props'];

    expect(array_column($props['actions'], 'name'))->toBe(['pause', 'resume', 'cancel'])
        ->and(($this->rowActions)($props['actions'], 'pause')['disabledReason'])->toBeNull();
});

test('the detail poll follows the run, so a batch paused by a jam can be resumed from it', function () {
    /*
     * The failure this pins: a card fails mid-run, PrintJob::markFailed() pauses the batch,
     * and the operator is watching the detail page. If the poll reloads only the cards, the
     * Failed row appears while the header still reads Printing and Resume is still carrying
     * the disabledReason computed at page load, so the one screen that exists to recover a
     * halted run cannot resume it without a manual reload.
     *
     * The partial header is read off the page's own usePoll call rather than typed here,
     * which is what makes this a test of what the browser actually asks for.
     */
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing, 'total_jobs' => 2]);
    $card = ($this->card)($batch, ['status' => PrintJobStatusEnum::Printing]);

    $props = actingAs($this->admin)
        ->get(route('admin.print-batches.show', $batch))
        ->viewData('page')['props'];

    expect($props['batch']['status']['label'])->toBe('Printing')
        ->and(($this->rowActions)($props['actions'], 'resume')['disabledReason'])->not->toBeNull();

    $card->markFailed('Card jam on the left feed roller');

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Paused);

    $source = file_get_contents(resource_path('js/Pages/Manage/PrintBatches/Show.vue'));
    preg_match('/usePoll\(\s*\d+\s*,\s*\{\s*only:\s*\[([^\]]*)\]/', $source, $matches);
    $only = collect(explode(',', $matches[1] ?? ''))
        ->map(fn (string $prop) => trim($prop, " \n\t'\""))
        ->filter()
        ->values();

    $polled = actingAs($this->admin)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Manage/PrintBatches/Show',
            'X-Inertia-Partial-Data' => $only->implode(','),
        ])
        ->get(route('admin.print-batches.show', $batch))
        ->json('props');

    $resume = collect($polled['actions'] ?? [])->firstWhere('name', 'resume');

    expect($polled['batch']['status']['label'] ?? null)->toBe('Paused')
        ->and($polled['batch']['progress']['failed'] ?? null)->toBe(1)
        ->and($resume)->not->toBeNull()
        ->and($resume['disabledReason'])->toBeNull()
        // The cards still reload with them; the fix widens the poll, it does not move it.
        ->and($polled['rows'][0]['cells']['status']['label'] ?? null)->toBe('Failed');
});

test('the card list renders the eight columns in order, in print order', function () {
    $batch = ($this->batch)();

    ($this->card)($batch, ['sequence' => 2]);
    ($this->card)($batch, ['sequence' => 1]);

    actingAs($this->admin)->get(route('admin.print-batches.show', $batch))
        ->assertInertia(fn (Assert $page) => $page
            ->where('columns.0', fn ($c) => $c['key'] === 'sequence' && $c['label'] === '#' && $c['sortable'])
            ->where('columns.1', fn ($c) => $c['key'] === 'badge' && $c['label'] === 'Badge' && $c['fallback'] === 'Deleted')
            ->where('columns.2', fn ($c) => $c['key'] === 'fursuit' && $c['label'] === 'Fursuit' && $c['fallback'] === 'Deleted')
            ->where('columns.3', fn ($c) => $c['key'] === 'status' && $c['type'] === 'badge')
            ->where('columns.4', fn ($c) => $c['key'] === 'completion_source' && $c['label'] === 'Finished by' && $c['fallback'] === 'Not finished')
            ->where('columns.5', fn ($c) => $c['key'] === 'verified_print_at' && $c['label'] === 'Verified' && $c['type'] === 'icon')
            ->where('columns.6', fn ($c) => $c['key'] === 'attempt_count' && $c['label'] === 'Tries' && $c['hiddenByDefault'])
            ->where('columns.7', fn ($c) => $c['key'] === 'error_message' && $c['label'] === 'Error' && $c['hiddenByDefault'])
            ->count('columns', 8)
            ->where('sort', ['key' => 'sequence', 'dir' => 'asc'])
            ->where('rows.0.cells.sequence', 1)
            ->etc()
        );

    $props = actingAs($this->admin)->get(route('admin.print-batches.show', $batch))->viewData('page')['props'];

    expect(array_column($props['columns'], 'key'))->toBe(MANAGE_PRINT_BATCH_CARD_COLUMNS)
        ->and($props['hiddenColumns'])->toBe(['attempt_count', 'error_message']);
});

test('a card names its badge and its fursuit, and says who finished it', function () {
    $batch = ($this->batch)();

    $badge = ($this->badge)(['custom_id' => '1042-1']);
    $badge->fursuit->update(['name' => 'Sunny']);

    ($this->card)($batch, [
        'printable_id' => $badge->id,
        'status' => PrintJobStatusEnum::Printed,
        'completion_source' => PrintCompletionSourceEnum::Firmware,
    ]);

    $cells = actingAs($this->admin)
        ->get(route('admin.print-batches.show', $batch))
        ->viewData('page')['props']['rows'][0]['cells'];

    expect($cells['badge'])->toBe('1042-1')
        ->and($cells['fursuit'])->toBe('Sunny')
        ->and($cells['status'])->toMatchArray(['label' => 'Printed', 'tone' => 'ok'])
        ->and($cells['completion_source'])->toBe(PrintCompletionSourceEnum::Firmware->label());
});

test('the verified icon carries the audit tooltip while nobody has vouched for the card', function () {
    $batch = ($this->batch)();

    $waiting = ($this->card)($batch, ['status' => PrintJobStatusEnum::Printed, 'sequence' => 1]);
    ($this->card)($batch, [
        'status' => PrintJobStatusEnum::Printed,
        'sequence' => 2,
        'verified_print_at' => now(),
        'verification_source' => PrintVerificationSourceEnum::Operator,
    ]);

    $rows = actingAs($this->admin)
        ->get(route('admin.print-batches.show', $batch))
        ->viewData('page')['props']['rows'];

    expect($rows[0]['cells']['verified_print_at'])->toMatchArray([
        'tone' => 'warn',
        'title' => 'Nobody has confirmed this card came out',
    ])
        ->and($rows[1]['cells']['verified_print_at'])->toMatchArray([
            'tone' => 'ok',
            'title' => PrintVerificationSourceEnum::Operator->label(),
        ])
        ->and($waiting->fresh()->verified_print_at)->toBeNull();
});

test('a card in the printer does not share a tone with one waiting behind a retry', function () {
    // The relation manager coloured Printing and Queued `primary`, the print-job list gave
    // Queued its own `info`; the finer of the two survives, and both stay apart from the
    // `warn` Pending and Retrying share, so the list still says which card is moving.
    $batch = ($this->batch)();

    $tone = function (PrintJobStatusEnum $status) use ($batch) {
        PrintJob::query()->delete();
        ($this->card)($batch, ['status' => $status]);

        return actingAs($this->admin)
            ->get(route('admin.print-batches.show', $batch))
            ->viewData('page')['props']['rows'][0]['cells']['status']['tone'];
    };

    expect([
        'pending' => $tone(PrintJobStatusEnum::Pending),
        'queued' => $tone(PrintJobStatusEnum::Queued),
        'printing' => $tone(PrintJobStatusEnum::Printing),
        'printed' => $tone(PrintJobStatusEnum::Printed),
        'failed' => $tone(PrintJobStatusEnum::Failed),
        'cancelled' => $tone(PrintJobStatusEnum::Cancelled),
        'retrying' => $tone(PrintJobStatusEnum::Retrying),
    ])->toBe([
        'pending' => 'warn',
        'queued' => 'info',
        'printing' => 'live',
        'printed' => 'ok',
        'failed' => 'danger',
        'cancelled' => 'idle',
        'retrying' => 'warn',
    ]);
});

test('the card list declares the unverified toggle and all seven job statuses', function () {
    $batch = ($this->batch)();
    ($this->card)($batch);

    $filters = actingAs($this->admin)
        ->get(route('admin.print-batches.show', $batch))
        ->viewData('page')['props']['filters'];

    expect(array_column($filters, 'key'))->toBe(['unverified', 'status'])
        ->and($filters[0])->toMatchArray(['type' => 'boolean', 'label' => 'Printed but unverified'])
        ->and($filters[1]['options'])->toHaveCount(7)
        ->and(collect($filters[1]['options'])->pluck('value')->all())->toContain('cancelled');
});

test('the card filters narrow the run', function () {
    $batch = ($this->batch)();

    ($this->card)($batch, ['status' => PrintJobStatusEnum::Printed, 'sequence' => 1]);
    ($this->card)($batch, ['status' => PrintJobStatusEnum::Cancelled, 'sequence' => 2]);
    ($this->card)($batch, [
        'status' => PrintJobStatusEnum::Printed,
        'sequence' => 3,
        'verified_print_at' => now(),
        'verification_source' => PrintVerificationSourceEnum::Operator,
    ]);

    $rows = fn (array $query) => actingAs($this->admin)
        ->get(route('admin.print-batches.show', [$batch, ...$query]))
        ->viewData('page')['props']['rows'];

    expect($rows([]))->toHaveCount(3)
        ->and($rows(['filter' => ['unverified' => 1]]))->toHaveCount(1)
        ->and($rows(['filter' => ['status' => 'cancelled']]))->toHaveCount(1);
});

test('the card list searches the badge id and the fursuit name through the morph', function () {
    $batch = ($this->batch)();

    $one = ($this->badge)(['custom_id' => '1042-1']);
    $one->fursuit->update(['name' => 'Sunny']);

    $two = ($this->badge)(['custom_id' => '2099-1']);
    $two->fursuit->update(['name' => 'Rain']);

    ($this->card)($batch, ['printable_id' => $one->id, 'sequence' => 1]);
    ($this->card)($batch, ['printable_id' => $two->id, 'sequence' => 2]);

    $rows = fn (string $term) => actingAs($this->admin)
        ->get(route('admin.print-batches.show', [$batch, 'search' => $term]))
        ->viewData('page')['props']['rows'];

    expect($rows('2099'))->toHaveCount(1)
        ->and($rows('2099')[0]['cells']['badge'])->toBe('2099-1')
        ->and($rows('Sunny'))->toHaveCount(1)
        ->and($rows('Sunny')[0]['cells']['fursuit'])->toBe('Sunny');
});

/* Verify. */

test('verify is offered only for a printed card that nobody has vouched for', function () {
    $batch = ($this->batch)();

    ($this->card)($batch, ['status' => PrintJobStatusEnum::Pending, 'sequence' => 1]);
    ($this->card)($batch, ['status' => PrintJobStatusEnum::Printed, 'sequence' => 2]);
    ($this->card)($batch, [
        'status' => PrintJobStatusEnum::Printed,
        'sequence' => 3,
        'verified_print_at' => now(),
        'verification_source' => PrintVerificationSourceEnum::Operator,
    ]);

    $rows = actingAs($this->admin)
        ->get(route('admin.print-batches.show', $batch))
        ->viewData('page')['props']['rows'];

    /*
     * View is on every row - a card is opened from the run that owns it now that Print Jobs
     * has no rail entry - so the predicate under test is which rows also carry verify.
     */
    $names = fn (array $row) => collect($row['actions'])->pluck('name')->all();

    expect($names($rows[0]))->toBe(['view'])
        ->and($names($rows[2]))->toBe(['view'])
        ->and($names($rows[1]))->toBe(['view', 'verify'])
        ->and($rows[1]['actions'][1])->toMatchArray([
            'name' => 'verify',
            'label' => 'Mark verified',
            'icon' => 'circle-check',
            'tone' => 'ok',
        ])
        ->and($rows[1]['actions'][1]['confirm'])->toBe([
            'heading' => 'Confirm this card',
            'description' => 'Only do this with the printed card in front of you. This records that a human checked it.',
            'submit' => 'Confirm',
        ]);
});

test('verifying stamps the card, mirrors onto the badge and recalculates the counters', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing]);
    $badge = ($this->badge)();

    $card = ($this->card)($batch, ['printable_id' => $badge->id, 'status' => PrintJobStatusEnum::Printed]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.jobs.verify', [$batch, $card]))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Card verified');

    $card->refresh();

    expect($card->verified_print_at)->not->toBeNull()
        ->and($card->verification_source)->toBe(PrintVerificationSourceEnum::Operator)
        ->and($card->verified_by_id)->toBe($this->admin->id)
        ->and($badge->fresh()->verified_print_at)->not->toBeNull()
        ->and($batch->fresh()->verified_count)->toBe(1);
});

test('verifying a card that has already been vouched for changes nothing', function () {
    $batch = ($this->batch)();
    $stamped = now()->subHour();

    $card = ($this->card)($batch, [
        'status' => PrintJobStatusEnum::Printed,
        'verified_print_at' => $stamped,
        'verification_source' => PrintVerificationSourceEnum::Camera,
    ]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.jobs.verify', [$batch, $card]))
        ->assertSessionHas(MANAGE_PRINT_BATCH_TOAST, 'Nothing was verified');

    expect($card->fresh()->verification_source)->toBe(PrintVerificationSourceEnum::Camera);
});

test('a card from another batch cannot be verified through this one', function () {
    $batch = ($this->batch)();
    $other = ($this->batch)();

    $card = ($this->card)($other, ['status' => PrintJobStatusEnum::Printed]);

    actingAs($this->admin)
        ->post(route('admin.print-batches.jobs.verify', [$batch, $card]))
        ->assertNotFound();

    expect($card->fresh()->verified_print_at)->toBeNull();
});

/* Nothing acts on its own. */

test('reading the list or a batch moves nothing', function () {
    $batch = ($this->batch)(['status' => PrintBatchStatusEnum::Printing, 'total_jobs' => 1]);
    $badge = ($this->badge)(['printing_locked_at' => now()]);
    $card = ($this->card)($batch, ['printable_id' => $badge->id, 'status' => PrintJobStatusEnum::Printed]);

    actingAs($this->admin)->get(route('admin.print-batches.index'))->assertSuccessful();
    actingAs($this->admin)->get(route('admin.print-batches.show', $batch))->assertSuccessful();
    // The polls, twice each.
    actingAs($this->admin)->get(route('admin.print-batches.index'))->assertSuccessful();
    actingAs($this->admin)->get(route('admin.print-batches.show', $batch))->assertSuccessful();

    expect($batch->fresh())
        ->status->toBe(PrintBatchStatusEnum::Printing)
        ->pause_reason->toBeNull()
        ->and($card->fresh()->verified_print_at)->toBeNull()
        ->and($badge->fresh()->printing_locked_at)->not->toBeNull();
});
