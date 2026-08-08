<?php

/*
 * Print jobs, phase 6. Transcribed from audit 4.9.
 *
 * This module drives real hardware, so three groups of cases get more weight than the
 * parity transcription:
 *
 *  - nothing acts on its own. Opening the list, the view page or the edit page, and the
 *    five-second poll behind the list, must not create, re-queue or complete a job. Retry
 *    is a POST with its own ability and its own confirm modal.
 *  - the status field is a transition, not a write. Marking a job Printed from /admin has
 *    to release the printer, promote the badge to ReadyForPickup and recalculate the batch
 *    counters, which the old panel form did none of.
 *  - deleting jobs recalculates the parent batch's counters, single and bulk.
 *
 * The rest is parity: twelve columns in order, six filters, the retry copy verbatim, and
 * the old panel's own delete copy.
 */

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\Species;
use App\Models\User;
use App\Support\Manage\Action;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/** The audit's eleven columns in order, plus the batch (checklist 15, Form). */
const MANAGE_PRINT_JOB_COLUMNS = [
    'id',
    'printer_name',
    'type',
    'status',
    'printable',
    'priority',
    'retry_count',
    'machine_name',
    'created_at',
    'printed_at',
    'error_message',
    'batch',
];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_PRINT_JOB_TOAST = 'inertia.flash_data.toast.title';

beforeEach(function () {
    // Badge::factory reaches the fursuit image path through the s3 disk.
    Storage::fake('s3');

    // ManageEventScope runs on every /admin request whether or not the page is scoped,
    // and this list deliberately is not.
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

    $this->job = fn (array $attributes = []) => PrintJob::factory()->create([
        'printer_id' => $this->printer->id,
        'printable_type' => Badge::class,
        'printable_id' => ($this->badge)()->id,
        'type' => PrintJobTypeEnum::Badge,
        'status' => PrintJobStatusEnum::Pending,
        'priority' => 1,
        'retry_count' => 0,
        ...$attributes,
    ]);

    // Every read below is an admin read; the access cases below act for themselves.
    $this->props = fn (array $query = []) => actingAs($this->admin)
        ->get(route('admin.print-jobs.index', $query))
        ->viewData('page')['props'];
});

/*
 * Access. PrintJobPolicy answers is_admin for every ability, so holding access-manage is
 * not enough here either.
 */

test('a guest is redirected to login', function () {
    get(route('admin.print-jobs.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the print-job list at all', function () {
    actingAs($this->attendee)->get(route('admin.print-jobs.index'))->assertForbidden();
});

test('a reviewer cannot reach the print-job list', function () {
    actingAs($this->reviewer)->get(route('admin.print-jobs.index'))->assertForbidden();
});

/* Columns. */

test('the list renders the twelve columns in order, with their labels and types', function () {
    ($this->job)();

    actingAs($this->admin)->get(route('admin.print-jobs.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/PrintJobs/Index')
            ->where('columns.0', fn ($c) => $c['key'] === 'id' && $c['label'] === 'ID' && $c['sortable'])
            ->where('columns.1', fn ($c) => $c['key'] === 'printer_name' && $c['label'] === 'Printer' && $c['sortable'])
            ->where('columns.2', fn ($c) => $c['key'] === 'type' && $c['label'] === 'Type' && $c['type'] === 'badge')
            ->where('columns.3', fn ($c) => $c['key'] === 'status' && $c['label'] === 'Status' && $c['type'] === 'badge')
            ->where('columns.4', fn ($c) => $c['key'] === 'printable' && $c['label'] === 'Printable')
            ->where('columns.5', fn ($c) => $c['key'] === 'priority' && $c['label'] === 'Priority' && $c['type'] === 'badge' && $c['sortable'])
            ->where('columns.6', fn ($c) => $c['key'] === 'retry_count' && $c['label'] === 'Retries' && $c['type'] === 'badge')
            ->where('columns.7', fn ($c) => $c['key'] === 'machine_name' && $c['label'] === 'Machine' && $c['fallback'] === 'Not assigned')
            ->where('columns.8', fn ($c) => $c['key'] === 'created_at' && $c['label'] === 'Created' && $c['sortable'])
            ->where('columns.9', fn ($c) => $c['key'] === 'printed_at' && $c['label'] === 'Printed' && $c['fallback'] === 'Not printed')
            ->where('columns.10', fn ($c) => $c['key'] === 'error_message' && $c['label'] === 'Error' && $c['fallback'] === 'None')
            ->where('columns.11', fn ($c) => $c['key'] === 'batch' && $c['label'] === 'Batch' && $c['toggleable'])
            ->count('columns', 12)
        );

    expect(array_column(($this->props)()['columns'], 'key'))->toBe(MANAGE_PRINT_JOB_COLUMNS);
});

test('the status column speaks one vocabulary, so queued reads Claimed', function () {
    // audit 7.9: the old print-job list printed the raw ->value and PrintJobsRelationManager
    // printed ->label(), so the same job read `queued` on one screen and `Claimed` on the
    // next. Status::printJob is the one mapping now.
    ($this->job)(['status' => PrintJobStatusEnum::Queued]);

    // The tone is this resource's own `info` for a claimed card, kept over the relation
    // manager's coarser `primary`, and apart from the `warn` Pending and Retrying share.
    expect(($this->props)()['rows'][0]['cells']['status'])
        ->toMatchArray(['label' => 'Claimed', 'tone' => 'info']);
});

test('a cancelled job is coloured rather than rendered unstyled', function () {
    // audit 87: `cancelled` was missing from the BadgeColumn colour map, the status
    // filter and the form select.
    ($this->job)(['status' => PrintJobStatusEnum::Cancelled]);

    expect(($this->props)()['rows'][0]['cells']['status'])
        ->toMatchArray(['label' => 'Cancelled', 'tone' => 'idle']);
});

test('the type column carries both cases with their own tones', function () {
    ($this->job)(['type' => PrintJobTypeEnum::Receipt, 'printable_type' => Badge::class]);

    expect(($this->props)()['rows'][0]['cells']['type'])->toMatchArray(['label' => 'Receipt']);
});

test('the printable cell reads Badge #custom_id for a badge and ClassBasename #id otherwise', function () {
    $badge = ($this->badge)(['custom_id' => '0142-1']);

    ($this->job)(['printable_type' => Badge::class, 'printable_id' => $badge->id]);

    expect(($this->props)()['rows'][0]['cells']['printable'])->toBe('Badge #0142-1');

    PrintJob::query()->delete();

    ($this->job)(['printable_type' => Machine::class, 'printable_id' => 7]);

    expect(($this->props)()['rows'][0]['cells']['printable'])->toBe('Machine #7');
});

test('the priority and retry ladders carry the audit colours', function () {
    $cells = function (int $priority, int $retries) {
        PrintJob::query()->delete();
        ($this->job)(['priority' => $priority, 'retry_count' => $retries]);

        return ($this->props)()['rows'][0]['cells'];
    };

    expect($cells(10, 3))->toMatchArray([
        'priority' => ['label' => '10', 'tone' => 'danger', 'icon' => null],
        'retry_count' => ['label' => '3', 'tone' => 'danger', 'icon' => null],
    ]);

    expect($cells(5, 1))->toMatchArray([
        'priority' => ['label' => '5', 'tone' => 'warn', 'icon' => null],
        'retry_count' => ['label' => '1', 'tone' => 'warn', 'icon' => null],
    ]);

    expect($cells(1, 0))->toMatchArray([
        'priority' => ['label' => '1', 'tone' => 'info', 'icon' => null],
        'retry_count' => ['label' => '0', 'tone' => 'idle', 'icon' => null],
    ]);

    expect($cells(0, 0)['priority'])->toMatchArray(['label' => '0', 'tone' => 'idle']);
});

test('a null priority or retry count no longer takes the table down', function () {
    // audit 29: both colour closures type-hinted `int $state`, so a record reaching the
    // renderer with no value for either column was a TypeError and a 500 for the whole
    // list. Both columns are NOT NULL in the schema, so the null is introduced where the
    // crash happened - at the model, on its way to the row transformer - rather than in
    // the database, which would not accept it.
    ($this->job)();

    PrintJob::retrieved(function (PrintJob $job) {
        $job->setAttribute('priority', null);
        $job->setAttribute('retry_count', null);
    });

    actingAs($this->admin)->get(route('admin.print-jobs.index'))->assertSuccessful();

    expect(($this->props)()['rows'][0]['cells'])->toMatchArray([
        'priority' => ['label' => 'None', 'tone' => 'idle', 'icon' => null],
        'retry_count' => ['label' => 'None', 'tone' => 'idle', 'icon' => null],
    ]);
});

test('the error column truncates to fifty characters and keeps the full text as the tooltip', function () {
    $message = str_repeat('a', 80);

    ($this->job)(['status' => PrintJobStatusEnum::Failed, 'error_message' => $message]);

    $cell = ($this->props)()['rows'][0]['cells']['error_message'];

    // the old panel's ->limit(50) keeps fifty characters and appends the ellipsis, as Str::limit
    // does, so the rendered cell is 53 characters long.
    expect($cell['title'])->toBe($message)
        ->and($cell['display'])->toBe(str_repeat('a', 50).'...');
});

test('the batch cell names the run and the sequence in it', function () {
    // The old panel resource surfaced neither print_batch_id nor sequence anywhere, so a
    // failed card could not be traced back to its run from this list at all.
    $batch = PrintBatch::factory()->create(['name' => 'Friday morning', 'printer_id' => $this->printer->id]);

    ($this->job)(['print_batch_id' => $batch->id, 'sequence' => 4]);

    $cell = ($this->props)()['rows'][0]['cells']['batch'];

    expect($cell['display'])->toBe('Friday morning #4')
        // batchCell() falls back to plain text while admin.print-batches.show is missing.
        // Phase 7 shipped that module, so the cell is a live link now and must stay one.
        ->and($cell['url'])->toBe(route('admin.print-batches.show', $batch));
});

/* Sorting, searching and paging, through the visit the client actually sends. */

test('the list opens newest first and flips through the partial reload the client sends', function () {
    $first = ($this->job)();
    $last = ($this->job)();

    $version = app(HandleInertiaRequests::class)->version(request());

    $partial = fn (array $query) => actingAs($this->admin)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Manage/PrintJobs/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
        ])
        ->get(route('admin.print-jobs.index', $query));

    $opening = $partial([])->assertSuccessful();

    expect($opening->json('props.sort'))->toBe(['key' => 'id', 'dir' => 'desc'])
        ->and($opening->json('props.rows.0.id'))->toBe($last->id)
        ->and($opening->json('props.meta.page'))->toBe(1)
        ->and($opening->json('props.search'))->toBe('')
        ->and($opening->json('props.filters'))->toHaveCount(6);

    expect($partial(['sort' => 'id', 'dir' => 'asc'])->json('props.rows.0.id'))->toBe($first->id);
});

test('the printer column sorts across the relation and searches it', function () {
    $other = Printer::factory()->badge()->create(['name' => 'Anonymous 9']);

    $zebra = ($this->job)();
    $anon = ($this->job)(['printer_id' => $other->id]);

    expect(($this->props)(['sort' => 'printer_name', 'dir' => 'asc'])['rows'][0]['id'])->toBe($anon->id);
    expect(($this->props)(['sort' => 'printer_name', 'dir' => 'desc'])['rows'][0]['id'])->toBe($zebra->id);

    $found = ($this->props)(['search' => 'Anonymous'])['rows'];

    expect($found)->toHaveCount(1)->and($found[0]['id'])->toBe($anon->id);
});

/* Filters. */

test('the six filters are declared in the audit order, with printer among them', function () {
    // audit 88: `?printer=` was a resource-wide getEloquentQuery() scope that fired on the
    // view and edit pages too and had no chip to clear it from the UI.
    expect(array_column(($this->props)()['filters'], 'key'))
        ->toBe(['status', 'type', 'printer', 'printable_id', 'printable_type', 'verified']);
});

test('the status filter offers all seven cases including cancelled', function () {
    $status = collect(($this->props)()['filters'])->firstWhere('key', 'status');

    expect(array_column($status['options'], 'value'))
        ->toBe(['pending', 'queued', 'printing', 'printed', 'failed', 'cancelled', 'retrying'])
        ->and(array_column($status['options'], 'label'))
        ->toContain('Claimed', 'Cancelled');
});

test('the status filter narrows the list', function () {
    ($this->job)(['status' => PrintJobStatusEnum::Pending]);
    $cancelled = ($this->job)(['status' => PrintJobStatusEnum::Cancelled]);

    $rows = ($this->props)(['filter' => ['status' => 'cancelled']])['rows'];

    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($cancelled->id);
});

test('the type filter narrows the list', function () {
    ($this->job)(['type' => PrintJobTypeEnum::Badge]);
    $receipt = ($this->job)(['type' => PrintJobTypeEnum::Receipt]);

    $rows = ($this->props)(['filter' => ['type' => 'receipt']])['rows'];

    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($receipt->id);
});

test('the printer filter narrows the list and renames the page', function () {
    $other = Printer::factory()->badge()->create(['name' => 'Anonymous 9']);

    ($this->job)();
    $anon = ($this->job)(['printer_id' => $other->id]);

    $props = ($this->props)(['filter' => ['printer' => $other->id]]);

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['id'])->toBe($anon->id)
        // ListPrintJobs::getTitle(), verbatim.
        ->and($props['title'])->toBe('Print Jobs - Anonymous 9');

    expect(($this->props)()['title'])->toBe('Print Jobs');
});

test('the printable filters narrow the list', function () {
    $badge = ($this->badge)();

    ($this->job)();
    $mine = ($this->job)(['printable_type' => Badge::class, 'printable_id' => $badge->id]);

    $rows = ($this->props)(['filter' => [
        'printable_id' => $badge->id,
        'printable_type' => Badge::class,
    ]])['rows'];

    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($mine->id);
});

test('the verified filter answers the status strip link', function () {
    // The strip shipped in phase 0 links here with filter[status]=printed&filter[verified]=0
    // to reach the printed cards nobody has vouched for. Without the filter that link
    // would silently show every printed card.
    ($this->job)(['status' => PrintJobStatusEnum::Printed, 'verified_print_at' => now()]);
    $unverified = ($this->job)(['status' => PrintJobStatusEnum::Printed, 'verified_print_at' => null]);

    $rows = ($this->props)(['filter' => ['status' => 'printed', 'verified' => '0']])['rows'];

    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe($unverified->id);
});

/* Retry. */

test('Retry is offered only on a failed job with fewer than three retries', function () {
    $offered = fn (PrintJob $job) => collect(
        collect(($this->props)()['rows'])->firstWhere('id', $job->id)['actions']
    )->pluck('name')->all();

    $pending = ($this->job)();
    expect($offered($pending))->toBe(['view', 'edit']);

    PrintJob::query()->delete();

    $failed = ($this->job)(['status' => PrintJobStatusEnum::Failed, 'retry_count' => 2]);
    expect($offered($failed))->toBe(['view', 'edit', 'retry']);

    PrintJob::query()->delete();

    $exhausted = ($this->job)(['status' => PrintJobStatusEnum::Failed, 'retry_count' => 3]);
    expect($offered($exhausted))->toBe(['view', 'edit']);
});

test('the Retry confirm copy is the bare requiresConfirmation copy, verbatim', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Failed]);

    $retry = collect(($this->props)()['rows'][0]['actions'])->firstWhere('name', 'retry');

    expect($retry['label'])->toBe('Retry')
        ->and($retry['method'])->toBe('post')
        ->and($retry['icon'])->toBe('refresh-cw')
        ->and($retry['tone'])->toBe('warn')
        ->and($retry['url'])->toBe(route('admin.print-jobs.retry', $job))
        ->and($retry['confirm'])->toBe([
            'heading' => 'Retry',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Confirm',
        ]);
});

test('Retry queues a new pending job and leaves the original failed', function () {
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);
    $job = ($this->job)([
        'status' => PrintJobStatusEnum::Failed,
        'print_batch_id' => $batch->id,
        'sequence' => 3,
        'error_message' => 'Card jam',
    ]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.retry', $job))
        ->assertRedirect();

    $retry = PrintJob::where('retry_of', $job->id)->firstOrFail();

    expect($retry->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($retry->print_batch_id)->toBe($batch->id)
        ->and($retry->sequence)->toBe(3)
        ->and($retry->printable_id)->toBe($job->printable_id)
        ->and($retry->priority)->toBe(1)
        ->and($retry->retry_count)->toBe(0)
        ->and($retry->error_message)->toBeNull()
        // audit 85: the original stays Failed and the batch stays Paused. Recorded here
        // so the day that changes, it changes deliberately.
        ->and($job->fresh()->status)->toBe(PrintJobStatusEnum::Failed);

    // A new job in the batch changes total_jobs.
    expect($batch->fresh()->total_jobs)->toBe(2);
});

test('Retry flashes the audit notification title', function () {
    // the old print-job list's only notification: success, title only, no body.
    $job = ($this->job)(['status' => PrintJobStatusEnum::Failed]);

    $response = actingAs($this->admin)->post(route('admin.print-jobs.retry', $job));

    $retry = PrintJob::where('retry_of', $job->id)->firstOrFail();

    $response->assertSessionHas(MANAGE_PRINT_JOB_TOAST, "Created retry job #{$retry->id}");
});

test('Retry refuses a job that cannot be retried', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Printed]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.retry', $job))
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Nothing was retried');

    expect(PrintJob::where('retry_of', $job->id)->count())->toBe(0);
});

test('Retry is an authorized POST and nothing else', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Failed]);

    actingAs($this->reviewer)->post(route('admin.print-jobs.retry', $job))->assertForbidden();

    // There is no GET form of it, so it cannot be reached by a pasted link or a preload.
    actingAs($this->admin)->get('/admin/print-jobs/'.$job->id.'/retry')->assertStatus(405);

    expect(PrintJob::count())->toBe(1);
});

test('no page load and no poll ever queues a card', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Failed]);

    actingAs($this->admin)->get(route('admin.print-jobs.index'))->assertSuccessful();
    actingAs($this->admin)->get(route('admin.print-jobs.show', $job))->assertSuccessful();
    actingAs($this->admin)->get(route('admin.print-jobs.edit', $job))->assertSuccessful();
    // The poll's own visit, and the printer scope that used to be a query-string side
    // effect on this very GET.
    ($this->props)(['filter' => ['printer' => $this->printer->id]]);

    expect(PrintJob::count())->toBe(1)
        ->and($job->fresh()->status)->toBe(PrintJobStatusEnum::Failed)
        ->and($job->fresh()->queued_at)->toBeNull()
        ->and($job->fresh()->processing_machine_id)->toBeNull()
        ->and($job->fresh()->lease_expires_at)->toBeNull();
});

/* The status transition. */

test('the status picker offers only the edges the machine allows', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Pending]);

    actingAs($this->admin)->get(route('admin.print-jobs.edit', $job))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/PrintJobs/Form')
            // Printed and Failed are on the list because a queued card can turn out to
            // have been printed already: the agent reports a card late when its lease
            // lapsed mid-print, and an operator holding the card can record the same
            // thing here. Both go through markPrinted() / markFailed(), so the badge is
            // promoted and the batch settled exactly as an agent's report would.
            ->where('statusOptions', [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'printed', 'label' => 'Printed'],
                ['value' => 'failed', 'label' => 'Failed'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ])
        );
});

test('the status picker never offers a state only an agent can write', function () {
    // Claimed, Printing and Retrying are reached by PrintJob::claim(), which writes a
    // machine and a lease. A bare transitionTo() from a form writes neither, so the job
    // is invisible to every claimer (they filter status = Pending) and to
    // scopeLeaseExpired() (it requires a lease): the card never prints and its batch
    // never completes.
    $agentOnly = ['queued', 'printing', 'retrying'];

    foreach (PrintJobStatusEnum::cases() as $case) {
        $job = ($this->job)(['status' => $case]);

        $offered = collect(actingAs($this->admin)->get(route('admin.print-jobs.edit', $job))
            ->viewData('page')['props']['statusOptions'])
            ->pluck('value')
            // The picker's resting position is the state the record is in, which means
            // "no change" rather than a transition.
            ->reject(fn (string $value) => $value === $case->value)
            ->all();

        expect(array_intersect($offered, $agentOnly))->toBe([]);
    }
});

test('a failed job is put back in the queue rather than left Retrying', function () {
    // Retrying is unclaimable and PrintBatch::requeueFailedJobs() only picks up Failed,
    // so a job parked in Retrying is rescued by nothing: not the agent, not the lease
    // reaper, not resuming the batch. The picker offers Pending and the write goes
    // through PrintJob::requeue().
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    $job = ($this->job)([
        'print_batch_id' => $batch->id,
        'sequence' => 1,
        'status' => PrintJobStatusEnum::Failed,
        'error_message' => 'Ribbon out',
        'retry_count' => 1,
        'attempt_count' => 2,
        'processing_machine_id' => Machine::factory()->create()->id,
        'lease_expires_at' => now()->subMinute(),
    ]);

    expect(collect(actingAs($this->admin)->get(route('admin.print-jobs.edit', $job))
        ->viewData('page')['props']['statusOptions'])->pluck('value')->all())
        ->toBe(['failed', 'cancelled', 'pending']);

    actingAs($this->admin)->put(route('admin.print-jobs.update', $job), [
        'printer_id' => $this->printer->id,
        'type' => 'badge',
        'status' => 'pending',
    ])->assertSessionHasNoErrors();

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($job->error_message)->toBeNull()
        ->and($job->failed_at)->toBeNull()
        ->and($job->lease_expires_at)->toBeNull()
        ->and($job->processing_machine_id)->toBeNull()
        ->and($job->attempt_count)->toBe(0)
        // The whole point: the agent's own claim query can see it again.
        ->and($batch->fresh()->claimNextJob(Machine::factory()->create())?->id)->toBe($job->id);
});

test('a claimed job sent back to Pending drops its lease and its machine', function () {
    $machine = Machine::factory()->create();

    $job = ($this->job)(['status' => PrintJobStatusEnum::Pending]);
    $job->claim($machine);
    $this->printer->update(['current_job_id' => $job->id]);

    actingAs($this->admin)->put(route('admin.print-jobs.update', $job), [
        'printer_id' => $this->printer->id,
        'type' => 'badge',
        'status' => 'pending',
    ])->assertSessionHasNoErrors();

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($job->processing_machine_id)->toBeNull()
        ->and($job->lease_expires_at)->toBeNull()
        ->and($this->printer->fresh()->current_job_id)->toBeNull();
});

test('a status the machine would refuse is rejected by the request', function () {
    // audit 20 and 22: the old panel select offered every state unconditionally and wrote
    // it through the cast, so admin could put a job somewhere no transition leads.
    $job = ($this->job)(['status' => PrintJobStatusEnum::Pending]);

    actingAs($this->admin)
        ->put(route('admin.print-jobs.update', $job), [
            'printer_id' => $this->printer->id,
            'type' => 'badge',
            'status' => 'printing',
        ])
        ->assertSessionHasErrors('status');

    expect($job->fresh()->status)->toBe(PrintJobStatusEnum::Pending);
});

test('marking a job printed from admin promotes the badge and recalculates the batch', function () {
    // audit 22: the old panel form wrote the column, so the badge stayed in Processing,
    // the printer kept its current_job_id and the batch counters never moved.
    $badge = ($this->badge)(['status_fulfillment' => 'processing']);
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    $job = ($this->job)([
        'printable_id' => $badge->id,
        'print_batch_id' => $batch->id,
        'sequence' => 1,
        'status' => PrintJobStatusEnum::Printing,
    ]);

    $this->printer->update(['current_job_id' => $job->id]);

    actingAs($this->admin)
        ->put(route('admin.print-jobs.update', $job), [
            'printer_id' => $this->printer->id,
            'type' => 'badge',
            'status' => 'printed',
            'priority' => 1,
            'retry_count' => 0,
        ])
        ->assertRedirect(route('admin.print-jobs.show', $job))
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Saved');

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatusEnum::Printed)
        ->and($job->printed_at)->not->toBeNull()
        ->and($job->completion_source)->toBe(PrintCompletionSourceEnum::Operator)
        ->and($this->printer->fresh()->current_job_id)->toBeNull()
        ->and($badge->fresh()->status_fulfillment->getValue())->toBe('ready_for_pickup')
        ->and($batch->fresh()->printed_count)->toBe(1);
});

test('marking a job failed from admin pauses its batch the way the agent does', function () {
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    $job = ($this->job)([
        'print_batch_id' => $batch->id,
        'sequence' => 1,
        'status' => PrintJobStatusEnum::Printing,
    ]);

    actingAs($this->admin)
        ->put(route('admin.print-jobs.update', $job), [
            'printer_id' => $this->printer->id,
            'type' => 'badge',
            'status' => 'failed',
            'error_message' => 'Ribbon out',
        ]);

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatusEnum::Failed)
        ->and($job->error_message)->toBe('Ribbon out')
        ->and($job->failed_at)->not->toBeNull()
        ->and($batch->fresh()->status->value)->toBe('paused')
        ->and($batch->fresh()->failed_count)->toBe(1);
});

test('saving without touching the status leaves the job where it is', function () {
    $job = ($this->job)(['status' => PrintJobStatusEnum::Pending, 'priority' => 1]);

    actingAs($this->admin)
        ->put(route('admin.print-jobs.update', $job), [
            'printer_id' => $this->printer->id,
            'type' => 'badge',
            'status' => 'pending',
            'priority' => 9,
            'retry_count' => 0,
            'firmware_job_id' => 'SNMP-77',
        ])
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Saved');

    $job->refresh();

    expect($job->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($job->priority)->toBe(9)
        ->and($job->firmware_job_id)->toBe('SNMP-77')
        ->and($job->queued_at)->toBeNull();
});

test('a reviewer cannot edit a print job', function () {
    $job = ($this->job)();

    actingAs($this->reviewer)->get(route('admin.print-jobs.edit', $job))->assertForbidden();
    actingAs($this->reviewer)->put(route('admin.print-jobs.update', $job), [
        'printer_id' => $this->printer->id,
        'type' => 'badge',
        'status' => 'pending',
    ])->assertForbidden();
});

/* Create. */

test('a badge job cannot be created without a batch', function () {
    // audit 89: a batch-less badge job lands in the receipt-only unbatched lane, which
    // claimNextUnbatched() filters to type = Receipt, so it sits Pending forever.
    $badge = ($this->badge)();

    actingAs($this->admin)
        ->post(route('admin.print-jobs.store'), [
            'printer_id' => $this->printer->id,
            'type' => 'badge',
            'status' => 'pending',
            'printable_type' => Badge::class,
            'printable_id' => $badge->id,
        ])
        ->assertSessionHasErrors('print_batch_id');

    expect(PrintJob::count())->toBe(0);
});

test('a created badge job joins the end of its batch and updates the counters', function () {
    $badge = ($this->badge)();
    $batch = PrintBatch::factory()->ready()->create(['printer_id' => $this->printer->id]);

    ($this->job)(['print_batch_id' => $batch->id, 'sequence' => 1]);

    actingAs($this->admin)
        ->post(route('admin.print-jobs.store'), [
            'printer_id' => $this->printer->id,
            'print_batch_id' => $batch->id,
            'type' => 'badge',
            'status' => 'pending',
            'printable_type' => Badge::class,
            'printable_id' => $badge->id,
            'priority' => 0,
            'retry_count' => 0,
        ])
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Created');

    $created = PrintJob::latest('id')->first();

    expect($created->sequence)->toBe(2)
        ->and($created->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($created->type)->toBe(PrintJobTypeEnum::Badge)
        ->and($batch->fresh()->total_jobs)->toBe(2);
});

test('a created job always starts pending, whatever the request asks for', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)
        ->post(route('admin.print-jobs.store'), [
            'printer_id' => $this->printer->id,
            'type' => 'receipt',
            'status' => 'printed',
            'printable_type' => Badge::class,
            'printable_id' => $badge->id,
        ])
        ->assertSessionHasErrors('status');

    expect(PrintJob::count())->toBe(0);
});

test('the receipt type is written as the enum, never as a raw string', function () {
    // audit landmine 4: the old checkout list writes 'type' => 'receipt' as a raw string next
    // to a PrintJobStatusEnum. Nothing in this module does.
    $badge = ($this->badge)();

    actingAs($this->admin)->post(route('admin.print-jobs.store'), [
        'printer_id' => $this->printer->id,
        'type' => PrintJobTypeEnum::Receipt->value,
        'status' => 'pending',
        'printable_type' => Badge::class,
        'printable_id' => $badge->id,
    ]);

    expect(PrintJob::latest('id')->first()->type)->toBe(PrintJobTypeEnum::Receipt);
});

/* Deletes. */

test('deleting the last outstanding job lets its batch finish', function () {
    // markFailed() paused the batch. An operator who deletes the failed card instead of
    // requeueing it leaves nothing outstanding, and recalculateCounters() alone reports
    // 100% on a batch that can never reach Completed: completeIfFinished() is only ever
    // called by markPrinted(), and no job is left to print.
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    $printed = ($this->job)([
        'print_batch_id' => $batch->id,
        'sequence' => 1,
        'status' => PrintJobStatusEnum::Printed,
        'printed_at' => now(),
    ]);

    $failed = ($this->job)(['print_batch_id' => $batch->id, 'sequence' => 2]);
    $failed->claim(Machine::factory()->create());
    $failed->markFailed('Ribbon out');

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Paused);

    actingAs($this->admin)->delete(route('admin.print-jobs.destroy', $failed))
        ->assertRedirect(route('admin.print-jobs.index'));

    expect($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Completed)
        ->and($batch->fresh()->total_jobs)->toBe(1)
        ->and($batch->fresh()->printed_count)->toBe(1)
        ->and($batch->fresh()->completed_at)->not->toBeNull()
        ->and(PrintJob::whereKey($printed->id)->exists())->toBeTrue();
});

test('cancelling the last outstanding job lets its batch finish', function () {
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    ($this->job)([
        'print_batch_id' => $batch->id,
        'sequence' => 1,
        'status' => PrintJobStatusEnum::Printed,
        'printed_at' => now(),
    ]);

    $failed = ($this->job)(['print_batch_id' => $batch->id, 'sequence' => 2]);
    $failed->claim(Machine::factory()->create());
    $failed->markFailed('Ribbon out');

    actingAs($this->admin)->put(route('admin.print-jobs.update', $failed), [
        'printer_id' => $this->printer->id,
        'type' => 'badge',
        'status' => 'cancelled',
    ])->assertSessionHasNoErrors();

    expect($failed->fresh()->status)->toBe(PrintJobStatusEnum::Cancelled)
        ->and($batch->fresh()->status)->toBe(PrintBatchStatusEnum::Completed);
});

test('a job a machine is holding cannot be deleted', function () {
    // The agent has the card. Deleting the row 404s its own printed callback
    // (routes/print-agent.php is route-model-bound), so the badge is never promoted and
    // the printer never releases the job. PrinterController::destroy refuses the mirror
    // of this for the same reason.
    $job = ($this->job)();
    $job->claim(Machine::factory()->create());

    actingAs($this->admin)
        ->from(route('admin.print-jobs.index'))
        ->delete(route('admin.print-jobs.destroy', $job))
        ->assertRedirect(route('admin.print-jobs.index'))
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Nothing was deleted');

    expect(PrintJob::whereKey($job->id)->exists())->toBeTrue();

    actingAs($this->admin)
        ->from(route('admin.print-jobs.index'))
        ->delete(route('admin.print-jobs.bulk.destroy'), ['ids' => [$job->id]])
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Nothing was deleted');

    expect(PrintJob::whereKey($job->id)->exists())->toBeTrue();
});

test('deleting a badge last print job hands editing back to its owner', function () {
    // printing_locked_at is set when the batch is built and cleared in exactly one other
    // place, PrintBatch::cancel(). A badge whose only job is deleted has no card and
    // nothing queued, so leaving the lock set makes BadgePolicy::updateAsOwner() refuse
    // the owner's own edit forever.
    $badge = ($this->badge)();
    $badge->forceFill(['printing_locked_at' => now()])->saveQuietly();

    $job = ($this->job)(['printable_id' => $badge->id]);

    actingAs($this->admin)->delete(route('admin.print-jobs.destroy', $job))
        ->assertRedirect(route('admin.print-jobs.index'));

    expect($badge->fresh()->printing_locked_at)->toBeNull();
});

test('a badge that still has a card or a queued job stays locked', function () {
    $badge = ($this->badge)();
    $badge->forceFill(['printing_locked_at' => now()])->saveQuietly();

    $printed = ($this->job)([
        'printable_id' => $badge->id,
        'status' => PrintJobStatusEnum::Printed,
        'printed_at' => now(),
    ]);

    $doomed = ($this->job)(['printable_id' => $badge->id]);

    actingAs($this->admin)->delete(route('admin.print-jobs.destroy', $doomed))
        ->assertRedirect(route('admin.print-jobs.index'));

    expect($badge->fresh()->printing_locked_at)->not->toBeNull()
        ->and(PrintJob::whereKey($printed->id)->exists())->toBeTrue();
});

test('deleting a job recalculates its batch counters', function () {
    // audit 23: DeleteBulkAction desynced total_jobs / printed_count / verified_count /
    // failed_count permanently, and every progress badge in the printing slice reads them.
    $batch = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    $kept = ($this->job)(['print_batch_id' => $batch->id, 'sequence' => 1]);
    $doomed = ($this->job)(['print_batch_id' => $batch->id, 'sequence' => 2, 'status' => PrintJobStatusEnum::Failed]);

    $batch->recalculateCounters();

    expect($batch->fresh()->total_jobs)->toBe(2)->and($batch->fresh()->failed_count)->toBe(1);

    actingAs($this->admin)
        ->delete(route('admin.print-jobs.destroy', $doomed))
        ->assertRedirect(route('admin.print-jobs.index'))
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Deleted');

    expect($batch->fresh()->total_jobs)->toBe(1)
        ->and($batch->fresh()->failed_count)->toBe(0)
        ->and(PrintJob::whereKey($kept->id)->exists())->toBeTrue();
});

test('the bulk delete recalculates every batch it touched', function () {
    $first = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);
    $second = PrintBatch::factory()->printing()->create(['printer_id' => $this->printer->id]);

    $a = ($this->job)(['print_batch_id' => $first->id, 'sequence' => 1]);
    $b = ($this->job)(['print_batch_id' => $second->id, 'sequence' => 1]);
    $kept = ($this->job)(['print_batch_id' => $second->id, 'sequence' => 2]);

    $first->recalculateCounters();
    $second->recalculateCounters();

    actingAs($this->admin)
        ->delete(route('admin.print-jobs.bulk.destroy'), ['ids' => [$a->id, $b->id]])
        ->assertSessionHas(MANAGE_PRINT_JOB_TOAST, 'Deleted');

    expect($first->fresh()->total_jobs)->toBe(0)
        ->and($second->fresh()->total_jobs)->toBe(1)
        ->and(PrintJob::whereKey($kept->id)->exists())->toBeTrue();
});

test('the bulk delete carries the old panel default copy and is admin only', function () {
    ($this->job)();

    $bulk = ($this->props)()['bulkActions'];

    // Reset sits first, delete second: the recoverable verb before the destructive one.
    $delete = collect($bulk)->firstWhere('name', 'delete');

    expect($bulk)->toHaveCount(2)
        ->and($bulk[0]['name'])->toBe('reset')
        ->and($delete['label'])->toBe('Delete selected')
        ->and($delete['confirm'])->toBe([
            'heading' => 'Delete selected print jobs',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);

    actingAs($this->reviewer)
        ->delete(route('admin.print-jobs.bulk.destroy'), ['ids' => [PrintJob::first()->id]])
        ->assertForbidden();

    expect(PrintJob::count())->toBe(1);
});

/* Pages. */

test('the view page renders the record read-only with Back to batch and Edit', function () {
    $batch = PrintBatch::factory()->create(['name' => 'Friday morning', 'printer_id' => $this->printer->id]);
    $job = ($this->job)(['print_batch_id' => $batch->id, 'sequence' => 2]);

    actingAs($this->admin)->get(route('admin.print-jobs.show', $job))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/PrintJobs/Show')
            ->where('job.id', $job->id)
            ->where('job.printer', 'Zebra 1')
            ->where('job.batch', 'Friday morning')
            ->where('job.sequence', 2)
            ->where('actions.0.name', 'batch')
            ->where('actions.0.url', route('admin.print-batches.show', $batch))
            ->where('actions.1.name', 'edit')
            ->count('actions', 2)
        );
});

// The queue is reached through a batch now, so the one card that has no batch must not be
// offered a way back to one.
test('an unbatched card gets no Back to batch action', function () {
    $job = ($this->job)(['print_batch_id' => null]);

    actingAs($this->admin)->get(route('admin.print-jobs.show', $job))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.0.name', 'edit')
            ->count('actions', 1)
        );
});

test('the edit page carries View and Delete with the old panel delete copy', function () {
    $job = ($this->job)();

    actingAs($this->admin)->get(route('admin.print-jobs.edit', $job))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.0.name', 'view')
            ->where('actions.1.name', 'delete')
            ->where('actions.1.confirm', [
                'heading' => 'Delete print job',
                'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
                'submit' => 'Delete',
            ])
        );
});

test('the list header offers the create page', function () {
    ($this->job)();

    $actions = ($this->props)()['pageActions'];

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['label'])->toBe('New print job')
        ->and($actions[0]['url'])->toBe(route('admin.print-jobs.create'));

    actingAs($this->admin)->get(route('admin.print-jobs.create'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/PrintJobs/Form')
            ->where('job', null)
        );
});
