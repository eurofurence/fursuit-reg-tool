<?php

/*
 * Cross-module read-safety, phases 7 and 8 (integration pass).
 *
 * Each module already asserts its own corner of this (PrintBatchesTest "reading the list
 * or a batch moves nothing", CheckoutsTest "reading the list or the detail page writes
 * nothing", TseClientsTest "rendering the list or a record writes nothing", BadgePrintTest
 * "the five-second poll queues nothing"). Each of those checks only the tables its own
 * module owns, which is exactly the gap an integration pass has to close: the badges list
 * could queue a print job, the checkout detail page could touch a TSE record, and every one
 * of those module tests would still be green.
 *
 * So this file takes one fixture that has a row in every table the panel can write, snaps
 * every one of those tables, walks every GET the four new modules register (full visit and
 * the Inertia partial visit the polls actually send, twice each, because a poll fires more
 * than once), and compares the snapshot. The snapshot is not a row count: it carries the
 * mutable columns too, so a transition that swaps a badge's state or a cancel that stamps
 * a batch shows up as a diff even though no row appeared or vanished.
 */

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Manage\DbServiceController;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\Species;
use App\Models\Staff;
use App\Models\User;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/*
 * The tables a read must never move, and the columns that would betray a move. `id` is in
 * every list so a vanished row and an appeared row cannot cancel each other out in the
 * comparison.
 */
const MANAGE_WRITE_SENSITIVE_TABLES = [
    'print_jobs' => ['id', 'status', 'print_batch_id', 'printer_id', 'sequence', 'attempt_count', 'verified_print_at', 'printed_at', 'error_message'],
    'print_batches' => ['id', 'status', 'total_jobs', 'printed_jobs', 'failed_jobs', 'pause_reason', 'started_at', 'completed_at'],
    'badges' => ['id', 'status_fulfillment', 'status_payment', 'printed_at', 'printing_locked_at', 'total', 'updated_at'],
    'checkouts' => ['id', 'status', 'payment_method', 'subtotal', 'tax', 'total', 'remote_id', 'updated_at'],
    'checkout_items' => ['id', 'checkout_id', 'name', 'subtotal', 'tax', 'total'],
    'tse_clients' => ['id', 'remote_id', 'serial_number', 'state', 'updated_at'],
    'fursuits' => ['id', 'status', 'updated_at'],
    'printers' => ['id', 'is_active', 'type'],
];

/**
 * Every column of every sensitive table, ordered, as one comparable structure.
 */
function manageWriteSnapshot(): array
{
    $snapshot = [];

    foreach (MANAGE_WRITE_SENSITIVE_TABLES as $table => $columns) {
        $snapshot[$table] = DB::table($table)
            ->orderBy('id')
            ->get($columns)
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    return $snapshot;
}

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('local');

    // App\Observers\TseClientsObserver PUTs Fiskaly on write; the fake proves no read here
    // reached the wire either.
    Http::fake();

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);

    $this->printer = Printer::factory()->badge()->create(['name' => 'Zebra 1']);
    $this->receiptPrinter = Printer::factory()->create([
        'name' => 'Receipt Printer',
        'type' => PrintJobTypeEnum::Receipt,
        'is_active' => true,
    ]);

    $this->machine = Machine::factory()->create(['name' => 'Cashdesk 1']);
    $this->cashier = Staff::factory()->create(['name' => 'Desk Lead']);

    /*
     * A badge that is mid-run: locked, pending, and holding a printed card nobody has
     * verified. That is the state in which a stray write is most likely and most costly,
     * because a page that "helpfully" verifies or unlocks would look like progress.
     */
    $this->badge = Badge::factory()->create([
        'status_fulfillment' => 'pending',
        'status_payment' => 'paid',
        'printing_locked_at' => now()->subMinutes(5),
        'fursuit_id' => Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'user_id' => User::factory()->create()->id,
            'species_id' => Species::factory()->create()->id,
        ])->id,
    ]);

    $this->batch = PrintBatch::factory()->create([
        'name' => 'Batch 1000-1099',
        'printer_id' => $this->printer->id,
        'event_id' => $this->event->id,
        'status' => PrintBatchStatusEnum::Printing,
        'total_jobs' => 1,
    ]);

    $this->job = PrintJob::factory()->create([
        'print_batch_id' => $this->batch->id,
        'printer_id' => $this->printer->id,
        'printable_type' => Badge::class,
        'printable_id' => $this->badge->id,
        'type' => PrintJobTypeEnum::Badge,
        'status' => PrintJobStatusEnum::Printed,
        'sequence' => 1,
    ]);

    $this->checkout = Checkout::create([
        'remote_id' => 'REMOTE-1',
        'status' => Finished::class,
        'payment_method' => 'cash',
        'user_id' => User::factory()->create(['name' => 'Paying Attendee'])->id,
        'cashier_id' => $this->cashier->id,
        'machine_id' => $this->machine->id,
        'subtotal' => 1000,
        'tax' => 190,
        'total' => 1190,
        'fiskaly_data' => [],
    ]);

    $this->checkout->items()->create([
        'name' => 'Line item',
        'description' => [],
        'payable_type' => Badge::class,
        'payable_id' => $this->badge->id,
        'subtotal' => 1000,
        'tax' => 190,
        'total' => 1190,
    ]);

    $this->tseClient = TseClient::create([
        'remote_id' => 'fiskaly-client-0001',
        'serial_number' => 'TSE-SERIAL-AAA111',
        'state' => TseClientStateEnum::REGISTERED,
    ]);
});

/**
 * Every GET the four new modules register, as [label, url, partial-props].
 * The partial list is what the page's usePoll() actually asks for; an empty list means the
 * page declares no poll and only the full visit is walked.
 */
dataset('manage read pages', function () {
    return [
        // Phase 9. DashboardTest covers the numbers; this covers the one thing it cannot,
        // that computing them moves nothing in any other module's tables.
        'dashboard' => [fn () => route('manage.dashboard'), ['stats', 'charts']],
        'badges list' => [fn () => route('manage.badges.index'), ['rows', 'meta', 'bulkActions']],
        'badges edit page' => [fn () => route('manage.badges.edit', test()->badge), []],
        'corrupted totals report' => [fn () => route('manage.badges.corrupted-totals'), []],
        'print batches list' => [fn () => route('manage.print-batches.index'), ['rows', 'meta']],
        'print batch detail' => [fn () => route('manage.print-batches.show', test()->batch), ['batch', 'actions', 'rows', 'meta']],
        'checkouts list' => [fn () => route('manage.checkouts.index'), []],
        'checkout detail' => [fn () => route('manage.checkouts.show', test()->checkout), []],
        'tse clients list' => [fn () => route('manage.tse-clients.index'), []],
        'tse client detail' => [fn () => route('manage.tse-clients.show', test()->tseClient), []],
    ];
});

test('reading a page writes nothing anywhere in the panel', function (Closure $url, array $poll) {
    $before = manageWriteSnapshot();

    actingAs($this->admin);

    // The first paint, then the same visit again: a user re-opening the screen.
    $component = $this->get($url())->viewData('page')['component'];
    $this->get($url())->assertSuccessful();

    /*
     * The poll, twice, exactly as the Inertia client sends it. The component name has to
     * be read before the headers go on, because withHeaders() sets them for the rest of
     * the test and a partial visit answers JSON rather than a view.
     */
    if ($poll !== []) {
        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia\Inertia::getVersion(),
            'X-Inertia-Partial-Data' => implode(',', $poll),
            'X-Inertia-Partial-Component' => $component,
        ]);

        $this->get($url())->assertSuccessful();
        $this->get($url())->assertSuccessful();
    }

    expect(manageWriteSnapshot())->toEqual($before);

    // Nothing spoke to Fiskaly on the way through either.
    Http::assertNothingSent();
})->with('manage read pages');

test('walking every read page in one session still writes nothing', function () {
    $before = manageWriteSnapshot();

    actingAs($this->admin);

    foreach ([
        route('manage.badges.index'),
        route('manage.badges.edit', $this->badge),
        route('manage.badges.corrupted-totals'),
        route('manage.print-batches.index'),
        route('manage.print-batches.show', $this->batch),
        route('manage.checkouts.index'),
        route('manage.checkouts.show', $this->checkout),
        route('manage.tse-clients.index'),
        route('manage.tse-clients.show', $this->tseClient),
    ] as $url) {
        $this->get($url)->assertSuccessful();
    }

    expect(manageWriteSnapshot())->toEqual($before);

    // Spelled out per table, so a failure names the table rather than dumping the diff.
    expect(PrintJob::count())->toBe(1)
        ->and(PrintBatch::count())->toBe(1)
        ->and(Checkout::count())->toBe(1)
        ->and(TseClient::count())->toBe(1)
        ->and($this->batch->fresh()->status)->toBe(PrintBatchStatusEnum::Printing)
        ->and($this->job->fresh()->verified_print_at)->toBeNull()
        ->and($this->badge->fresh()->status_fulfillment->getMorphClass())->toBe($this->badge->status_fulfillment->getMorphClass())
        ->and($this->badge->fresh()->printing_locked_at)->not->toBeNull();
});

/*
 * Fiscal read-only. The routing half and the policy half are asserted separately, because
 * either one alone is a single point of failure: a route that does not exist today can be
 * added by the next module file, and a policy that refuses is worthless if a controller
 * never consults it.
 */

test('checkouts and TSE clients register no create, update or delete route', function () {
    foreach ([
        'manage.checkouts.create',
        'manage.checkouts.store',
        'manage.checkouts.edit',
        'manage.checkouts.update',
        'manage.checkouts.destroy',
        'manage.checkouts.bulk.destroy',
        'manage.tse-clients.create',
        'manage.tse-clients.store',
        'manage.tse-clients.edit',
        'manage.tse-clients.update',
        'manage.tse-clients.destroy',
        'manage.tse-clients.bulk.destroy',
    ] as $name) {
        expect(Route::has($name))->toBeFalse("route $name must not exist");
    }
});

test('an admin is refused create, update and delete on both fiscal records at the URL', function () {
    actingAs($this->admin);

    foreach ([
        ['post', '/admin/checkouts'],
        ['put', '/admin/checkouts/'.$this->checkout->id],
        ['patch', '/admin/checkouts/'.$this->checkout->id],
        ['delete', '/admin/checkouts/'.$this->checkout->id],
        ['get', '/admin/checkouts/create'],
        ['get', '/admin/checkouts/'.$this->checkout->id.'/edit'],
        ['post', '/admin/tse-clients'],
        ['put', '/admin/tse-clients/'.$this->tseClient->id],
        ['patch', '/admin/tse-clients/'.$this->tseClient->id],
        ['delete', '/admin/tse-clients/'.$this->tseClient->id],
        ['get', '/admin/tse-clients/create'],
        ['get', '/admin/tse-clients/'.$this->tseClient->id.'/edit'],
    ] as [$verb, $url]) {
        $response = $this->{$verb}($url);

        expect($response->getStatusCode())
            ->toBeIn([403, 404, 405], "$verb $url must not be a write path");
    }

    expect(Checkout::count())->toBe(1)
        ->and(TseClient::count())->toBe(1);
});

test('the checkout policy refuses create, update and delete for everyone', function () {
    // CheckoutPolicy answers what CheckoutResource's own canCreate/canEdit/canDelete
    // already answered, so nothing on either panel loses a screen to it.
    foreach ([$this->admin, User::factory()->create(['is_admin' => false, 'is_reviewer' => true])] as $user) {
        expect($user->can('create', Checkout::class))->toBeFalse()
            ->and($user->can('update', $this->checkout))->toBeFalse()
            ->and($user->can('delete', $this->checkout))->toBeFalse()
            ->and($user->can('restore', $this->checkout))->toBeFalse()
            ->and($user->can('forceDelete', $this->checkout))->toBeFalse();
    }
});

test('the TSE client policy stays admin-only rather than being closed under the legacy panel', function () {
    /*
     * TseClientPolicy is shared with Filament, which still has a create page and a row
     * EditAction for TSE clients until cutover. The two screens are recorded as defects
     * and the new module carries neither, but closing the ability would break a panel the
     * plan says keeps running - so the lock here is the routing table, asserted by
     * TseClientsTest, not a policy the other panel depends on.
     */
    $reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    expect($this->admin->can('create', TseClient::class))->toBeTrue()
        ->and($this->admin->can('update', $this->tseClient))->toBeTrue()
        ->and($reviewer->can('create', TseClient::class))->toBeFalse()
        ->and($reviewer->can('update', $this->tseClient))->toBeFalse()
        ->and($reviewer->can('delete', $this->tseClient))->toBeFalse();
});

/*
 * Phase 9 read-safety: the dashboard, the DB Service preview and both PDFs.
 *
 * These three are the panel's riskiest reads, and none of them is a list page the dataset
 * above can walk. The dashboard aggregates across every module's tables; the DB Service
 * preview runs the *analysis half* of the one repair that writes, against the same rows the
 * apply would zero; and each PDF renders a document from live badge data. A stray write in
 * any of them is invisible on screen, because all three legitimately show the operator the
 * numbers they would have changed.
 *
 * The proof is a snapshot taken twice with the reads in between, compared three ways: the
 * row count per table (a row appeared or vanished), the ordered column values (a row moved),
 * and one checksum over the whole structure (anything the first two spelled out was missed).
 * The checksum is not redundant with toEqual: it is what the failure message quotes when the
 * structures are too large to diff by eye.
 */

/** The phase-9 tables, which are the panel-wide ones plus the two this repair touches. */
const MANAGE_PHASE9_EXTRA_TABLES = [
    // What apply() writes: the zeroed money, the free marker, the payment state.
    'badges' => ['id', 'is_free_badge', 'total', 'subtotal', 'tax', 'status_payment', 'paid_at', 'updated_at'],
    // The entitlement the analysis reads. A preview that "helpfully" consumed one would show here.
    'event_users' => ['id', 'user_id', 'event_id', 'prepaid_badges'],
    // apply() logs one row per converted badge, so a preview that logged would show here too.
    'activity_log' => ['id', 'description', 'subject_id', 'subject_type', 'causer_id'],
];

function managePhase9Snapshot(): array
{
    $snapshot = manageWriteSnapshot();

    foreach (MANAGE_PHASE9_EXTRA_TABLES as $table => $columns) {
        $snapshot['phase9:'.$table] = DB::table($table)
            ->orderBy('id')
            ->get($columns)
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    return $snapshot;
}

function managePhase9Counts(): array
{
    $counts = [];

    foreach (array_keys(MANAGE_WRITE_SENSITIVE_TABLES + MANAGE_PHASE9_EXTRA_TABLES) as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function managePhase9Checksum(): string
{
    return md5(json_encode(managePhase9Snapshot()));
}

/**
 * A user holding an unused prepaid entitlement whose main badge was nonetheless charged:
 * exactly one row for the DB Service report to find, so the preview below is doing real
 * work rather than rendering an empty table.
 */
function manageSeedRepairableBadge(Event $event): Badge
{
    $owner = User::factory()->create(['name' => 'Prepaid Attendee']);

    EventUser::create([
        'user_id' => $owner->id,
        'event_id' => $event->id,
        'attendee_id' => 'TEST-'.$owner->id,
        'prepaid_badges' => 1,
        'valid_registration' => true,
    ]);

    return Badge::factory()->create([
        'custom_id' => '1234-1',
        'is_free_badge' => false,
        'extra_copy_of' => null,
        'status_payment' => 'unpaid',
        'subtotal' => 500,
        'tax' => 95,
        'total' => 595,
        'fursuit_id' => Fursuit::factory()->create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'species_id' => Species::factory()->create()->id,
        ])->id,
    ]);
}

test('the dashboard, the DB Service preview and both PDFs write nothing', function () {
    $repairable = manageSeedRepairableBadge($this->event);

    $before = managePhase9Snapshot();
    $beforeCounts = managePhase9Counts();
    $beforeChecksum = managePhase9Checksum();

    actingAs($this->admin)->withSession([EventScope::SESSION_ID => $this->event->id]);

    // 1. The dashboard, twice: the poll re-runs every aggregate.
    $this->get(route('manage.dashboard'))->assertSuccessful();
    $this->get(route('manage.dashboard'))->assertSuccessful();

    /*
     * 2. The DB Service preview, both halves. The POST is the button, the GET is the review
     * screen it redirects to, and the review recomputes the report on every load - so the
     * GET is walked twice, which is what a reload does.
     */
    $this->post(route('manage.maintenance.db-service.preview'))
        ->assertRedirect(route('manage.maintenance.db-service', ['review' => 1]));

    $review = $this->get(route('manage.maintenance.db-service', ['review' => 1]));
    $review->assertSuccessful();
    $this->get(route('manage.maintenance.db-service', ['review' => 1]))->assertSuccessful();

    // Non-vacuity: the preview really did find the seeded badge and really did price it.
    $report = $review->viewData('page')['props']['report'];

    expect($report['affected_badge_count'])->toBe(1)
        ->and($report['affected_user_count'])->toBe(1)
        ->and($report['total_refund_cents'])->toBe(595)
        ->and($report['rows'][0]['badge_id'])->toBe($repairable->id);

    // 3. Each PDF type, each rendered end to end rather than merely requested.
    $badgeList = $this->get(route('manage.tools.pdf.badge-list', [
        'pdf_type' => 'badge_list',
        'payment_status' => 'all',
        'badge_ranges' => '0-999,1000-1999',
    ]));

    $badgeList->assertSuccessful();

    $boxLabels = $this->get(route('manage.tools.pdf.box-labels', [
        'pdf_type' => 'box_labels',
        'title' => 'Box 1',
        'subtitle' => '1000-1099',
    ]));

    $boxLabels->assertSuccessful();

    /*
     * streamDownload defers the render into the callback, so without draining the stream
     * this asserts nothing about the code that actually builds the document. Both are
     * drained, and both have to produce a real PDF.
     */
    foreach ([$badgeList, $boxLabels] as $pdf) {
        expect($pdf->streamedContent())->toStartWith('%PDF');
    }

    // The three comparisons.
    expect(managePhase9Counts())->toEqual($beforeCounts)
        ->and(managePhase9Snapshot())->toEqual($before)
        ->and(managePhase9Checksum())->toBe($beforeChecksum);

    // Spelled out, so a failure names the thing that moved.
    $fresh = $repairable->fresh();

    expect($fresh->is_free_badge)->toBeFalse()
        ->and((int) $fresh->total)->toBe(595)
        ->and((int) $fresh->subtotal)->toBe(500)
        ->and((int) $fresh->tax)->toBe(95)
        ->and($fresh->paid_at)->toBeNull()
        ->and(DB::table('activity_log')->where('description', DbServiceController::ACTIVITY_DESCRIPTION)->count())->toBe(0);

    Http::assertNothingSent();
});

/*
 * The other half of read-safety for this module: the one endpoint that does write is the
 * one endpoint a reviewer must never reach. Gated twice - `can:manage-admin` on the route
 * group and `Gate::authorize('manage-admin')` in each of the three methods - so both are
 * asserted, the middleware through the URL and the gate directly.
 */
test('the DB Service apply flow is closed to a reviewer at the route and the gate', function () {
    $repairable = manageSeedRepairableBadge($this->event);
    $reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    // The reviewer is admitted to the panel at large, so a 403 below is this page's gate
    // and not a failed login.
    actingAs($reviewer);
    $this->get(route('manage.dashboard'))->assertSuccessful();

    expect(Gate::forUser($reviewer)->allows('access-manage'))->toBeTrue()
        ->and(Gate::forUser($reviewer)->allows('manage-admin'))->toBeFalse();

    foreach ([
        ['get', route('manage.maintenance.db-service')],
        ['post', route('manage.maintenance.db-service.preview')],
        ['post', route('manage.maintenance.db-service.apply')],
    ] as [$verb, $url]) {
        $this->{$verb}($url)->assertForbidden();
    }

    // Refused, and nothing converted on the way past.
    expect($repairable->fresh()->is_free_badge)->toBeFalse()
        ->and((int) $repairable->fresh()->total)->toBe(595);
});
