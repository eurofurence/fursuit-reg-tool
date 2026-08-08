<?php

/*
 * Printers, phase 6 (plan part 4.2). Transcribed from audit 4.7.
 *
 * This is the hardware screen: a live convention watches it to find out which printer has
 * stopped, and it carries the only inline write in the panel. So the cases below are less
 * about columns than about the three things that can go wrong on it.
 *
 *  - The table must not fall over. `$record->status->value ?? 'unknown'` had no null-safe
 *    operator, so a single printer row with a null status took the whole table down with
 *    an ErrorException (landmine 28), and an unrecognised condition string does the same
 *    shape of damage. Both are asserted, not assumed away.
 *  - The toggle must be a deliberate, authorized write. It was a CheckboxColumn that wrote
 *    to the database on one click with no confirm, no notification and no audit trail
 *    (audit 92). It now posts the state it means, and the 15s poll - which is the only
 *    thing that touches this page unattended - must never reach it.
 *  - A delete must not silently take print jobs with it. `print_jobs.printer_id` is
 *    cascadeOnDelete and nothing calls PrintBatch::recalculateCounters() afterwards
 *    (landmines 23 and 80).
 */

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrinterConditionEnum;
use App\Enum\PrinterStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Events\PrinterStatusUpdated;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Machine;
use App\Models\User;
use App\Support\Manage\Status;
use Illuminate\Support\Facades\Event as EventFacade;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\withHeaders;

/**
 * The audit's nine columns, in order, with the five condition columns plan 2.10 #27 adds
 * behind `status`.
 */
const MANAGE_PRINTER_COLUMNS = [
    'name',
    'type',
    'machine.name',
    'status',
    'condition',
    'condition_message',
    'cards',
    'condition_reported_at',
    'pending_jobs',
    'active_jobs',
    'failed_jobs',
    'is_active',
    'last_state_update',
];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_PRINTER_TOAST_TITLE = 'inertia.flash_data.toast.title';

beforeEach(function () {
    // ManageEventScope runs on every /admin request whether or not the page is scoped,
    // and this list deliberately is not: printers belong to the hall (plan 2.9).
    Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->attendee = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->machine = Machine::factory()->create(['name' => 'Hall 1 Terminal']);

    $this->props = fn (array $query = []) => get(route('admin.printers.index', $query))
        ->viewData('page')['props'];
});

/**
 * One row's cells, by printer name, out of the list envelope.
 *
 * @return array<string, mixed>
 */
function managePrinterCells(array $props, string $name): array
{
    $row = collect($props['rows'])->firstWhere('cells.name', $name);

    expect($row)->not->toBeNull();

    return $row['cells'];
}

/**
 * One row's actions, by printer name.
 *
 * @return array<int, array<string, mixed>>
 */
function managePrinterActions(array $props, string $name): array
{
    $row = collect($props['rows'])->firstWhere('cells.name', $name);

    expect($row)->not->toBeNull();

    return $row['actions'];
}

/**
 * A print job on a printer, without dragging a whole badge in behind it: `printable` is a
 * morph, so a scalar id is enough and the factory's Badge::factory() never runs.
 */
function managePrintJob(Printer $printer, PrintJobStatusEnum $status): PrintJob
{
    return PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'printable_type' => Badge::class,
        'printable_id' => 1,
        'status' => $status,
    ]);
}

/**
 * The visit useTableQuery makes, headers and all.
 *
 * @return array<string, mixed>
 */
function managePrinterPartial(string $url): array
{
    $version = app(HandleInertiaRequests::class)->version(request());

    $response = withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Manage/Printers/Index',
        'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
    ])->get($url);

    $response->assertOk();

    $props = $response->json('props');

    expect($props)->toHaveKeys(['rows', 'meta', 'filters', 'sort', 'search']);

    return $props;
}

/*
 * Access. PrinterPolicy answers is_admin for all seven abilities, so holding
 * access-manage is not enough here.
 */

test('a guest is redirected to login', function () {
    get(route('admin.printers.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the printer list at all', function () {
    actingAs($this->attendee);

    get(route('admin.printers.index'))->assertForbidden();
});

test('a reviewer holds access-manage but is refused every printer ability', function () {
    actingAs($this->reviewer);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'status' => PrinterStatusEnum::PAUSED,
        'is_active' => true,
    ]);

    get(route('admin.printers.index'))->assertForbidden();
    get(route('admin.printers.create'))->assertForbidden();
    post(route('admin.printers.store'), managePrinterPayload($this->machine))->assertForbidden();
    get(route('admin.printers.edit', $printer))->assertForbidden();
    put(route('admin.printers.update', $printer), managePrinterPayload($this->machine))->assertForbidden();
    delete(route('admin.printers.destroy', $printer))->assertForbidden();
    delete(route('admin.printers.bulk.destroy'), ['ids' => [$printer->id]])->assertForbidden();
    post(route('admin.printers.active', ['printer' => $printer, 'active' => 0]))->assertForbidden();
    post(route('admin.printers.clear-error', $printer))->assertForbidden();

    // Nothing was written on the way to any of those 403s.
    assertDatabaseHas('printers', [
        'id' => $printer->id,
        'is_active' => true,
        'status' => PrinterStatusEnum::PAUSED->value,
    ]);
});

/*
 * The envelope and the columns.
 */

test('the list ships the flat envelope with the audit columns in order', function () {
    actingAs($this->admin);

    Printer::factory()->create(['machine_id' => $this->machine->id]);

    $props = ($this->props)();

    expect($props)->toHaveKeys([
        'name', 'rows', 'columns', 'filters', 'sort', 'search', 'meta',
        'hiddenColumns', 'bulkActions', 'pageActions',
    ]);

    expect(collect($props['columns'])->pluck('key')->all())->toBe(MANAGE_PRINTER_COLUMNS);

    // PrinterResource declares `->filters([//])`, and none are added.
    expect($props['filters'])->toBe([]);

    // `->paginated(false)` becomes 200 a page with the pager visible (plan 2.3).
    expect($props['meta']['perPage'])->toBe(200);
});

test('the audit labels are carried verbatim', function () {
    actingAs($this->admin);

    $labels = collect(($this->props)()['columns'])->pluck('label', 'key');

    expect($labels['name'])->toBe('Name')
        ->and($labels['type'])->toBe('Type')
        ->and($labels['machine.name'])->toBe('Machine')
        ->and($labels['status'])->toBe('Status')
        ->and($labels['pending_jobs'])->toBe('Pending Jobs')
        ->and($labels['active_jobs'])->toBe('Active Jobs')
        ->and($labels['failed_jobs'])->toBe('Failed Jobs')
        ->and($labels['is_active'])->toBe('Active')
        ->and($labels['last_state_update'])->toBe('Last Update');
});

test('the type column keeps the raw enum-backed value', function () {
    actingAs($this->admin);

    Printer::factory()->receipt()->create(['machine_id' => $this->machine->id, 'name' => 'Till roll']);

    expect(managePrinterCells(($this->props)(), 'Till roll')['type'])
        ->toBe(PrintJobTypeEnum::Receipt->value);
});

test('the machine column names the related machine', function () {
    actingAs($this->admin);

    Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Zebra 1']);

    expect(managePrinterCells(($this->props)(), 'Zebra 1')['machine.name'])->toBe('Hall 1 Terminal');
});

/*
 * Status, condition, and the two null-safety cases.
 */

test('status renders through PrinterStatusEnum, including the cases the resource never mapped', function () {
    actingAs($this->admin);

    Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Jammed',
        // MEDIA_JAM is one of the six cases PrinterResource's colour map left out
        // entirely, so it rendered unstyled (audit 4.7 column 4).
        'status' => PrinterStatusEnum::MEDIA_JAM,
    ]);

    Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Ready',
        'status' => PrinterStatusEnum::IDLE,
    ]);

    $props = ($this->props)();

    expect(managePrinterCells($props, 'Jammed')['status'])
        ->toBe(['label' => 'Media Jam', 'tone' => 'danger', 'icon' => 'triangle-alert']);

    expect(managePrinterCells($props, 'Ready')['status']['label'])->toBe('Ready');
});

test('the status renderer is null-safe and unknown-value-safe', function () {
    // `$record->status->value ?? 'unknown'` had no null-safe operator, so a null status
    // emitted "Attempt to read property on null" and took the whole table with it
    // (landmine 28). The column now goes through Status::printer(), which answers for a
    // null and for a value it has never seen.
    //
    // Asserted on the renderer rather than on a row: `printers.status` is NOT NULL with
    // a default of 'idle' since 2025_08_23_135218, so the row the landmine describes
    // cannot be written through this schema at all. The guard stays because the model
    // is also handed around unsaved, and because a table must never 500 over a value.
    expect(Status::printer(null))
        ->toBe(['label' => 'Unknown', 'tone' => 'idle', 'icon' => 'circle-help'])
        ->and(Status::printer('a-state-nobody-declared'))
        ->toBe(['label' => 'Unknown', 'tone' => 'idle', 'icon' => 'circle-help']);
});

test('the condition columns surface the agent reading and its remedy', function () {
    actingAs($this->admin);

    Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Hungry',
        'condition' => PrinterConditionEnum::CardsOut->value,
        'condition_message' => null,
        'condition_reported_at' => now()->subMinutes(4),
        'cards_remaining' => 0,
        'cards_capacity' => 200,
    ]);

    $cells = managePrinterCells(($this->props)(), 'Hungry');

    expect($cells['condition']['label'])->toBe('Out of cards')
        ->and($cells['condition']['tone'])->toBe('danger')
        // PrinterConditionEnum::remedy(), which until now only the POS ever showed
        // (plan 2.10 #27).
        ->and($cells['condition_message']['display'])->toBe('Refill the card hopper.')
        ->and($cells['cards']['display'])->toBe('0 / 200')
        ->and($cells['condition_reported_at'])->not->toBeNull();
});

test('the agent message wins over the enum remedy when there is one', function () {
    actingAs($this->admin);

    Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Talkative',
        'condition' => PrinterConditionEnum::CardJam->value,
        'condition_message' => 'Card stuck at the flip station.',
    ]);

    $cells = managePrinterCells(($this->props)(), 'Talkative');

    expect($cells['condition_message']['display'])->toBe('Card stuck at the flip station.')
        ->and($cells['condition_message']['title'])->toBe('Open the printer and clear the jammed card.');
});

test('an unknown condition string renders rather than throwing', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Strange']);
    Printer::query()->whereKey($printer->id)->update(['condition' => 'something-the-agent-invented']);

    expect(managePrinterCells(($this->props)(), 'Strange')['condition']['label'])->toBe('Unknown state');
});

/*
 * The three job counts.
 */

test('the job counts are read off PrintJobStatusEnum, not hardcoded strings', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Busy']);

    managePrintJob($printer, PrintJobStatusEnum::Pending);
    managePrintJob($printer, PrintJobStatusEnum::Pending);
    // isActive() is exactly the Queued|Printing|Retrying trio the resource listed by hand.
    managePrintJob($printer, PrintJobStatusEnum::Queued);
    managePrintJob($printer, PrintJobStatusEnum::Printing);
    managePrintJob($printer, PrintJobStatusEnum::Retrying);
    managePrintJob($printer, PrintJobStatusEnum::Failed);
    // Neither pending, active nor failed: it must not be counted anywhere.
    managePrintJob($printer, PrintJobStatusEnum::Printed);

    $cells = managePrinterCells(($this->props)(), 'Busy');

    expect($cells['pending_jobs']['label'])->toBe('2')
        ->and($cells['pending_jobs']['tone'])->toBe('warn')
        ->and($cells['active_jobs']['label'])->toBe('3')
        ->and($cells['active_jobs']['tone'])->toBe('info')
        ->and($cells['failed_jobs']['label'])->toBe('1')
        ->and($cells['failed_jobs']['tone'])->toBe('danger');
});

test('a printer with no jobs counts zero on all three', function () {
    actingAs($this->admin);

    Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Idle']);

    $cells = managePrinterCells(($this->props)(), 'Idle');

    expect($cells['pending_jobs']['label'])->toBe('0')
        ->and($cells['active_jobs']['label'])->toBe('0')
        ->and($cells['failed_jobs']['label'])->toBe('0');
});

/*
 * Search, sort and the poll. Asserted through the partial visit the client actually
 * sends, so a nested envelope would fail here rather than pass quietly.
 */

test('the name search works under a partial visit even though Filament hid the box', function () {
    actingAs($this->admin);

    Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Zebra ZXP9']);
    Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Epson receipt']);

    $props = managePrinterPartial(route('admin.printers.index', ['search' => 'Zebra']));

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['cells']['name'])->toBe('Zebra ZXP9')
        ->and($props['search'])->toBe('Zebra');
});

test('the poll visit reloads the rows and writes nothing', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Zebra ZXP9',
        'is_active' => true,
        'status' => PrinterStatusEnum::PAUSED,
    ]);

    EventFacade::fake([PrinterStatusUpdated::class]);

    $props = managePrinterPartial(route('admin.printers.index'));

    expect($props['rows'])->toHaveCount(1);

    // The poll is a GET over rows and meta. It cannot flip is_active, it cannot clear an
    // error, and it cannot broadcast.
    assertDatabaseHas('printers', [
        'id' => $printer->id,
        'is_active' => true,
        'status' => PrinterStatusEnum::PAUSED->value,
    ]);
    EventFacade::assertNotDispatched(PrinterStatusUpdated::class);
});

/*
 * The inline is_active toggle.
 */

test('the toggle cell carries the state the click is asking for', function () {
    actingAs($this->admin);

    $on = Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'On', 'is_active' => true]);
    $off = Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Off', 'is_active' => false]);

    $props = ($this->props)();

    $onCell = managePrinterCells($props, 'On')['is_active'];
    $offCell = managePrinterCells($props, 'Off')['is_active'];

    expect($onCell['value'])->toBeTrue()
        ->and($onCell['url'])->toBe(route('admin.printers.active', ['printer' => $on->id, 'active' => 0]))
        ->and($offCell['value'])->toBeFalse()
        ->and($offCell['url'])->toBe(route('admin.printers.active', ['printer' => $off->id, 'active' => 1]));
});

test('the toggle endpoint writes and reports what it did', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Zebra ZXP9',
        'is_active' => true,
    ]);

    post(route('admin.printers.active', ['printer' => $printer, 'active' => 0]))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Printer deactivated');

    assertDatabaseHas('printers', ['id' => $printer->id, 'is_active' => false]);

    post(route('admin.printers.active', ['printer' => $printer, 'active' => 1]))
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Printer activated');

    assertDatabaseHas('printers', ['id' => $printer->id, 'is_active' => true]);
});

test('the toggle refuses a request that does not say which state it wants', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id, 'is_active' => true]);

    post(route('admin.printers.active', $printer))->assertSessionHasErrors('active');

    assertDatabaseHas('printers', ['id' => $printer->id, 'is_active' => true]);
});

test('a toggle that asks for the state the printer is already in writes nothing', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Zebra ZXP9',
        'is_active' => true,
    ]);

    $before = $printer->updated_at;

    post(route('admin.printers.active', ['printer' => $printer, 'active' => 1]))
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Nothing changed');

    expect($printer->fresh()->updated_at->equalTo($before))->toBeTrue();
});

/*
 * Clear error.
 */

test('clear error is offered disabled on a printer with nothing to clear', function () {
    actingAs($this->admin);

    Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Healthy',
        'is_active' => true,
        'status' => PrinterStatusEnum::IDLE,
    ]);

    $action = collect(managePrinterActions(($this->props)(), 'Healthy'))->firstWhere('name', 'clear-error');

    expect($action)->not->toBeNull()
        ->and($action['disabledReason'])->toBe('This printer has no paused or offline state to clear.')
        ->and($action['confirm']['heading'])->toBe('Clear printer error');
});

test('clear error is enabled on a paused printer and puts it back to ready', function () {
    actingAs($this->admin);

    EventFacade::fake([PrinterStatusUpdated::class]);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Paused one',
        'is_active' => true,
        'status' => PrinterStatusEnum::PAUSED,
        'last_error_message' => 'Ribbon out',
    ]);

    $action = collect(managePrinterActions(($this->props)(), 'Paused one'))->firstWhere('name', 'clear-error');

    expect($action['disabledReason'])->toBeNull();

    post(route('admin.printers.clear-error', $printer))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Printer error cleared');

    assertDatabaseHas('printers', [
        'id' => $printer->id,
        'status' => PrinterStatusEnum::IDLE->value,
        'last_error_message' => null,
    ]);

    // Printer::clearPrinterError() broadcasts, which is why the action is built on it
    // rather than on a local update: the POS learns without waiting for a poll.
    EventFacade::assertDispatched(PrinterStatusUpdated::class);
});

test('clear error refuses a printer that has no error', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'is_active' => true,
        'status' => PrinterStatusEnum::IDLE,
    ]);

    post(route('admin.printers.clear-error', $printer))
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Nothing was cleared');
});

test('clear error refuses when a second active printer shares the name', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Zebra',
        'is_active' => true,
        'status' => PrinterStatusEnum::OFFLINE,
    ]);

    // Printer::clearPrinterError() looks up by name, so this would be a coin toss over
    // which piece of hardware gets written.
    Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Zebra',
        'is_active' => true,
        'status' => PrinterStatusEnum::OFFLINE,
    ]);

    post(route('admin.printers.clear-error', $printer))
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Nothing was cleared');

    assertDatabaseHas('printers', ['id' => $printer->id, 'status' => PrinterStatusEnum::OFFLINE->value]);
});

/*
 * Deletes, and the counters they must not desync.
 */

test('a printer with no print jobs deletes', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id]);

    delete(route('admin.printers.destroy', $printer))
        ->assertRedirect(route('admin.printers.index'))
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Deleted');

    assertDatabaseMissing('printers', ['id' => $printer->id]);
});

test('a printer that still has print jobs is not deleted', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id]);
    $job = managePrintJob($printer, PrintJobStatusEnum::Printed);

    delete(route('admin.printers.destroy', $printer))
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Nothing was deleted');

    // printer_id cascades, so a successful delete would have taken the job with it and
    // left every batch counter reading a job that no longer exists.
    assertDatabaseHas('printers', ['id' => $printer->id]);
    assertDatabaseHas('print_jobs', ['id' => $job->id]);
});

test('the bulk delete is all or nothing when one printer still has jobs', function () {
    actingAs($this->admin);

    $clean = Printer::factory()->create(['machine_id' => $this->machine->id]);
    $busy = Printer::factory()->create(['machine_id' => $this->machine->id]);
    $job = managePrintJob($busy, PrintJobStatusEnum::Pending);

    delete(route('admin.printers.bulk.destroy'), ['ids' => [$clean->id, $busy->id]])
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Nothing was deleted');

    assertDatabaseHas('printers', ['id' => $clean->id]);
    assertDatabaseHas('printers', ['id' => $busy->id]);
    assertDatabaseHas('print_jobs', ['id' => $job->id]);
});

test('the bulk delete removes a clean selection with Filament copy', function () {
    actingAs($this->admin);

    $first = Printer::factory()->create(['machine_id' => $this->machine->id]);
    $second = Printer::factory()->create(['machine_id' => $this->machine->id]);

    $bulk = collect(($this->props)()['bulkActions'])->firstWhere('name', 'delete');

    expect($bulk['label'])->toBe('Delete selected')
        ->and($bulk['confirm']['heading'])->toBe('Delete selected printers')
        ->and($bulk['confirm']['description'])->toBe('Are you sure you would like to do this?')
        ->and($bulk['confirm']['submit'])->toBe('Delete');

    delete(route('admin.printers.bulk.destroy'), ['ids' => [$first->id, $second->id]])
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Deleted');

    assertDatabaseMissing('printers', ['id' => $first->id]);
    assertDatabaseMissing('printers', ['id' => $second->id]);
});

/*
 * The form. The create page is the one that could not render at all.
 */

test('the create page renders instead of throwing on a null record', function () {
    actingAs($this->admin);

    // Filament's default_paper_size closure type-hinted a non-nullable Printer $record,
    // so this page died with a TypeError the moment anyone reached it (landmine 27).
    $props = get(route('admin.printers.create'))->assertOk()->viewData('page')['props'];

    expect($props['printer'])->toBeNull()
        ->and($props['paperSizes'])->toBe([])
        ->and($props['condition'])->toBeNull()
        ->and(collect($props['types'])->pluck('label')->all())->toBe(['Receipt', 'Badge']);
});

test('the list offers the create button the Filament page removed', function () {
    actingAs($this->admin);

    $create = collect(($this->props)()['pageActions'])->firstWhere('name', 'create');

    expect($create['label'])->toBe('New printer')
        ->and($create['url'])->toBe(route('admin.printers.create'));
});

test('creating a printer writes an empty paper size list for the agent to fill in', function () {
    actingAs($this->admin);

    post(route('admin.printers.store'), managePrinterPayload($this->machine))
        ->assertRedirect(route('admin.printers.index'))
        ->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Created');

    $printer = Printer::query()->where('name', 'Zebra ZXP9')->firstOrFail();

    expect($printer->paper_sizes)->toBe([])
        ->and($printer->type)->toBe(PrintJobTypeEnum::Badge)
        // `is_active` carries no boolean cast on the model, so this is the driver's 1.
        ->and((bool) $printer->is_active)->toBeTrue();

    // The reported sizes reach the edit page as rows, never as a JSON document: the panel
    // has no raw-JSON field anywhere, and this one was the last of them. A printer that
    // has reported nothing has no rows, and the page says so rather than printing `{}`.
    expect(get(route('admin.printers.edit', $printer))->viewData('page')['props']['printer'])
        ->not->toHaveKey('paper_sizes')
        ->and(get(route('admin.printers.edit', $printer))->viewData('page')['props']['printer']['reportedPaperSizes'])
        ->toBe([]);
});

test('the reported paper sizes reach the edit page as rows rather than as JSON', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id]);

    $rows = get(route('admin.printers.edit', $printer))
        ->viewData('page')['props']['printer']['reportedPaperSizes'];

    // Name on its own, every other reported key rendered as `key: value` in the order the
    // agent sent it, and a nested measurement joined rather than json-encoded.
    expect($rows)->toBe([
        ['name' => 'A4', 'detail' => 'width: 210 · height: 297 · mm: 210 × 297'],
        ['name' => 'Letter', 'detail' => 'width: 216 · height: 279 · mm: 216 × 279'],
    ]);
});

test('the create form refuses a paper size the printer has never reported', function () {
    actingAs($this->admin);

    post(route('admin.printers.store'), managePrinterPayload($this->machine, ['default_paper_size' => 'A4']))
        ->assertSessionHasErrors('default_paper_size');

    assertDatabaseMissing('printers', ['name' => 'Zebra ZXP9']);
});

test('the edit form offers only the sizes this printer reported and saves one', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'name' => 'Zebra ZXP9',
        'default_paper_size' => 'A4',
    ]);

    $props = get(route('admin.printers.edit', $printer))->assertOk()->viewData('page')['props'];

    expect(collect($props['paperSizes'])->pluck('value')->all())->toBe(['A4', 'Letter'])
        ->and($props['printer']['default_paper_size'])->toBe('A4')
        ->and($props['condition'])->not->toBeNull();

    put(route('admin.printers.update', $printer), managePrinterPayload($this->machine, [
        'name' => 'Zebra ZXP9',
        'default_paper_size' => 'Letter',
    ]))->assertSessionHas(MANAGE_PRINTER_TOAST_TITLE, 'Saved');

    expect($printer->fresh()->default_paper_size)->toBe('Letter');

    put(route('admin.printers.update', $printer), managePrinterPayload($this->machine, [
        'default_paper_size' => 'A3',
    ]))->assertSessionHasErrors('default_paper_size');
});

test('the form cannot overwrite the paper sizes the agent reported', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id, 'name' => 'Zebra ZXP9']);
    $reported = $printer->paper_sizes;

    put(route('admin.printers.update', $printer), managePrinterPayload($this->machine, [
        'paper_sizes' => [['name' => 'Forged', 'width' => 1, 'height' => 1, 'mm' => [1, 1]]],
    ]))->assertRedirect(route('admin.printers.index'));

    expect($printer->fresh()->paper_sizes)->toBe($reported);
});

test('the edit page carries the delete action Filament put on its header', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create(['machine_id' => $this->machine->id]);

    $actions = get(route('admin.printers.edit', $printer))->viewData('page')['props']['actions'];

    $delete = collect($actions)->firstWhere('name', 'delete');

    expect($delete['confirm']['heading'])->toBe('Delete printer')
        ->and($delete['confirm']['description'])->toBe('Are you sure you would like to do this?')
        ->and($delete['confirm']['submit'])->toBe('Delete');
});

test('the condition panel carries the reading and the remedy', function () {
    actingAs($this->admin);

    $printer = Printer::factory()->create([
        'machine_id' => $this->machine->id,
        'condition' => PrinterConditionEnum::RibbonLow->value,
        'cards_remaining' => 40,
        'cards_capacity' => 200,
        'last_error_message' => 'Ribbon at 12 percent',
        'handling_machine_name' => 'Hall 1 Terminal',
    ]);

    $condition = get(route('admin.printers.edit', $printer))->viewData('page')['props']['condition'];

    expect($condition['condition']['label'])->toBe('Ribbon low')
        ->and($condition['condition']['tone'])->toBe('warn')
        ->and($condition['remedy'])->toBe('Replace the colour ribbon.')
        ->and($condition['cards'])->toBe('40 / 200')
        ->and($condition['lastErrorMessage'])->toBe('Ribbon at 12 percent')
        ->and($condition['handlingMachineName'])->toBe('Hall 1 Terminal');
});

/**
 * The form's own fields, so a payload is not retyped in six cases.
 *
 * @return array<string, mixed>
 */
function managePrinterPayload(Machine $machine, array $overrides = []): array
{
    return array_merge([
        'name' => 'Zebra ZXP9',
        'type' => PrintJobTypeEnum::Badge->value,
        'machine_id' => $machine->id,
        'default_paper_size' => null,
        'is_active' => true,
    ], $overrides);
}
