<?php

/*
 * The list envelope, checked across every module at once (plan part 1, the phase-1
 * lesson).
 *
 * Each module tests its own columns, filters and copy. This file tests the one thing no
 * module can test for the others: that the visit the client actually sends comes back
 * with a row set that moved.
 *
 * The failure it exists to catch is silent. useTableQuery reloads `rows`, `meta`,
 * `filters`, `sort` and `search` as an Inertia partial visit, and Inertia filters
 * partials by top-level key. A module that nests its envelope under a single `table`
 * prop still renders correctly on a full page load and still passes every assertion
 * about its columns - and then sorting, filtering and paging do nothing at all, because
 * the reloaded keys are not top-level and come back empty. So every case here issues the
 * real partial visit, headers and all, and asserts the rows changed rather than that a
 * column was declared sortable.
 *
 * The sidebar case is the other integration question: each module registers its own
 * routes and Navigation drops any item whose route does not exist, so only a test that
 * runs with every module loaded can say the rail picked all of them up.
 */

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Cancelled as CheckoutCancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished as CheckoutFinished;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Machine;
use App\Models\Species;
use App\Models\Staff;
use App\Models\SumUpReader;
use App\Models\User;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/**
 * The visit useTableQuery makes: X-Inertia plus the five reloaded keys, against the
 * component that is already on screen.
 *
 * @return array<string, mixed>
 */
function manageListPartial(string $url, string $component): array
{
    $version = app(HandleInertiaRequests::class)->version(request());

    $response = withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
    ])->get($url);

    $response->assertOk();

    $props = $response->json('props');

    // All five keys have to come back, and `rows` has to be a list: an envelope nested
    // under one prop answers this visit with an empty object.
    expect($props)->toHaveKeys(['rows', 'meta', 'filters', 'sort', 'search']);
    expect($props['rows'])->toBeArray();

    return $props;
}

beforeEach(function () {
    Storage::fake('s3');

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->event = Event::factory()->create(['name' => 'Eurofurence 29', 'starts_at' => now()->addDays(30)]);

    $this->scoped = function () {
        actingAs($this->admin);
        session([EventScope::SESSION_ID => $this->event->id, EventScope::SESSION_CHOSEN => true]);
    };
});

test('the events list sorts and paginates under a partial visit', function () {
    Event::factory()->create(['name' => 'Alpha', 'starts_at' => now()->subYears(3)]);
    Event::factory()->create(['name' => 'Zulu', 'starts_at' => now()->addYears(3)]);
    Event::factory()->count(9)->create();

    actingAs($this->admin);

    $ascending = manageListPartial('/admin/events?sort=starts_at&dir=asc', 'Manage/Events/Index');
    $descending = manageListPartial('/admin/events?sort=starts_at&dir=desc', 'Manage/Events/Index');

    expect($ascending['sort'])->toBe(['key' => 'starts_at', 'dir' => 'asc'])
        ->and($ascending['rows'][0]['cells']['name'])->toBe('Alpha')
        ->and($descending['rows'][0]['cells']['name'])->toBe('Zulu');

    $first = manageListPartial('/admin/events?per_page=10&page=1', 'Manage/Events/Index');
    $second = manageListPartial('/admin/events?per_page=10&page=2', 'Manage/Events/Index');

    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);

    // EventResource declares neither filters nor a searchable column, and the envelope
    // still has to carry both keys.
    expect($ascending['filters'])->toBe([])->and($ascending['search'])->toBe('');
});

test('the fursuits list sorts, searches, filters and paginates under a partial visit', function () {
    $species = Species::factory()->create(['name' => 'Wolf']);

    foreach (['Alpha', 'Bravo', 'Zulu'] as $name) {
        Fursuit::factory()->create([
            'name' => $name,
            'event_id' => $this->event->id,
            'species_id' => $species->id,
            'user_id' => User::factory()->create(['name' => 'Owner '.$name])->id,
            'status' => Pending::class,
        ]);
    }

    ($this->scoped)();

    expect(manageListPartial('/admin/fursuits', 'Manage/Fursuits/Index')['rows'])->toHaveCount(3);

    $searched = manageListPartial('/admin/fursuits?search=Alpha', 'Manage/Fursuits/Index');
    expect($searched['rows'])->toHaveCount(1)->and($searched['search'])->toBe('Alpha');

    $ascending = manageListPartial('/admin/fursuits?sort=user_name&dir=asc', 'Manage/Fursuits/Index');
    $descending = manageListPartial('/admin/fursuits?sort=user_name&dir=desc', 'Manage/Fursuits/Index');
    expect($ascending['rows'][0]['cells']['name'])->toBe('Alpha')
        ->and($descending['rows'][0]['cells']['name'])->toBe('Zulu');

    // The status filter defaults to pending, so asking for approved has to empty the set
    // and the filter has to come back holding what was asked for.
    $filtered = manageListPartial('/admin/fursuits?filter[status]=approved', 'Manage/Fursuits/Index');
    expect($filtered['rows'])->toBe([])->and($filtered['filters'][0]['value'])->toBe('approved');

    expect(manageListPartial('/admin/fursuits?per_page=10&page=2', 'Manage/Fursuits/Index')['meta']['page'])->toBe(2);
});

test('the badges list sorts, searches, filters and paginates under a partial visit', function () {
    $species = Species::factory()->create(['name' => 'Wolf']);

    foreach (['0100', '0200', '0300'] as $attendee) {
        $owner = User::factory()->create(['name' => 'Owner '.$attendee]);
        EventUser::factory()->create([
            'user_id' => $owner->id,
            'event_id' => $this->event->id,
            'attendee_id' => $attendee,
        ]);
        $fursuit = Fursuit::factory()->create([
            'name' => 'Suit'.$attendee,
            'event_id' => $this->event->id,
            'species_id' => $species->id,
            'user_id' => $owner->id,
            'status' => Approved::class,
        ]);
        Badge::factory()->create(['fursuit_id' => $fursuit->id, 'total' => 100, 'is_free_badge' => false]);
    }

    ($this->scoped)();

    expect(manageListPartial('/admin/badges', 'Manage/Badges/Index')['rows'])->toHaveCount(3);

    expect(manageListPartial('/admin/badges?search=Suit0100', 'Manage/Badges/Index')['rows'])->toHaveCount(1);

    // The default sort is the attendee id, compared numerically on a string column.
    $ascending = manageListPartial('/admin/badges?sort=sort_attendee_id&dir=asc', 'Manage/Badges/Index');
    $descending = manageListPartial('/admin/badges?sort=sort_attendee_id&dir=desc', 'Manage/Badges/Index');
    expect($ascending['rows'][0]['id'])->not->toBe($descending['rows'][0]['id']);

    expect(manageListPartial('/admin/badges?filter[is_free_badge]=1', 'Manage/Badges/Index')['rows'])->toBe([]);

    expect(manageListPartial('/admin/badges?per_page=10&page=2', 'Manage/Badges/Index')['meta']['page'])->toBe(2);
});

test('adding, changing and removing a filter chip is the same partial visit', function () {
    /*
     * The three requests the Shopify-style bar makes, in the order an operator makes
     * them, against the real partial visit rather than against a flag on a component.
     *
     * The removal cases are the ones with teeth. A filter the module gave a default is
     * removed by sending Filter::CLEARED, because dropping the key means "not set" and
     * the server answers that with the default again. A filter with no default is removed
     * by dropping the key, which is what keeps an unapplied filter genuinely absent from
     * a URL an operator is about to paste into chat.
     */
    $species = Species::factory()->create(['name' => 'Wolf']);

    foreach ([Pending::class, Approved::class] as $status) {
        Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'species_id' => $species->id,
            'user_id' => User::factory()->create()->id,
            'status' => $status,
        ]);
    }

    ($this->scoped)();

    // The declared default is on the bar from the first paint, with a chip to show for it.
    $opened = manageListPartial('/admin/fursuits', 'Manage/Fursuits/Index');
    expect($opened['rows'])->toHaveCount(1)
        ->and($opened['filters'][0]['value'])->toBe('pending')
        ->and($opened['filters'][0]['chipLabel'])->toBe('Status');

    // Changing the chip's value.
    $changed = manageListPartial('/admin/fursuits?filter[status]=approved', 'Manage/Fursuits/Index');
    expect($changed['rows'])->toHaveCount(1)
        ->and($changed['filters'][0]['value'])->toBe('approved');

    // Removing it. Defaulted, so the chip leaves the token behind.
    $removed = manageListPartial('/admin/fursuits?filter[status]=__none', 'Manage/Fursuits/Index');
    expect($removed['rows'])->toHaveCount(2)
        ->and($removed['filters'][0]['value'])->toBe('');

    // The badge list's attendee range, the type that had no chip before: both bounds, one
    // bound, then removed by absence because it declares no default.
    foreach (['0100', '0900'] as $attendee) {
        $owner = User::factory()->create();
        EventUser::factory()->create([
            'user_id' => $owner->id,
            'event_id' => $this->event->id,
            'attendee_id' => $attendee,
        ]);
        $fursuit = Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'species_id' => $species->id,
            'user_id' => $owner->id,
            'status' => Approved::class,
        ]);
        Badge::factory()->create(['fursuit_id' => $fursuit->id, 'total' => 100]);
    }

    $ranged = manageListPartial(
        '/admin/badges?filter[attendee_id_range][min]=1&filter[attendee_id_range][max]=200',
        'Manage/Badges/Index',
    );
    expect($ranged['rows'])->toHaveCount(1)
        ->and(collect($ranged['filters'])->firstWhere('key', 'attendee_id_range')['value'])
        ->toBe(['min' => '1', 'max' => '200']);

    $halfRange = manageListPartial(
        '/admin/badges?filter[attendee_id_range][min]=200',
        'Manage/Badges/Index',
    );
    expect($halfRange['rows'])->toHaveCount(1);

    $noRange = manageListPartial('/admin/badges', 'Manage/Badges/Index');
    expect($noRange['rows'])->toHaveCount(2)
        ->and(collect($noRange['filters'])->firstWhere('key', 'attendee_id_range')['value'])
        ->toBe(['min' => '', 'max' => '']);
});

test('the users and special-code lists still answer the same visit', function () {
    User::factory()->count(12)->create();

    actingAs($this->admin);

    $first = manageListPartial('/admin/users?per_page=10&page=1', 'Manage/Users/Index');
    $second = manageListPartial('/admin/users?per_page=10&page=2', 'Manage/Users/Index');

    expect($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);

    manageListPartial('/admin/special-codes', 'Manage/SpecialCodes/Index');
});

test('the corrupted-totals report renders', function () {
    ($this->scoped)();

    get('/admin/badges/corrupted-totals')->assertOk();
});

/*
 * The shared image cell. Both modules that declare one wanted Filament's
 * ImageColumn->circular() and neither could add it alone, because the shape lives in
 * Column and DataTable rather than in either controller (checklist 4.2 column 1, 4.3
 * column 5).
 */
test('both image columns declare the circular avatar shape', function () {
    actingAs($this->admin);
    session([EventScope::SESSION_ID => $this->event->id, EventScope::SESSION_CHOSEN => true]);

    $columnFor = function (string $url, string $key) {
        $columns = get($url)->viewData('page')['props']['columns'];

        return collect($columns)->firstWhere('key', $key);
    };

    expect($columnFor('/admin/fursuits', 'image'))->toMatchArray(['type' => 'image', 'circular' => true])
        ->and($columnFor('/admin/badges', 'fursuit.image'))->toMatchArray(['type' => 'image', 'circular' => true]);
});

// Audit 50: the file every defaultImageUrl points at was never shipped, so a badge with
// no artwork rendered a broken image on the badge list and on the public badge page.
test('the image placeholder every fallback points at exists', function () {
    expect(public_path('images/placeholder.png'))->toBeReadableFile();
});

/*
 * The phase-5 and phase-6 modules. Machines, SumUp Readers and Printers declare no
 * sortable column, which is the Filament resources' own shape: none of the three called
 * ->sortable() on anything. Their cases assert the two levers those lists do have (search
 * where a column declares it, filters where the resource declares them) plus paging, and
 * assert the sort key that comes back is the declared default rather than whatever was
 * asked for, so a column silently gaining a sort would show up here.
 */

test('the machines list searches, filters and paginates under a partial visit', function () {
    Machine::factory()->create(['name' => 'Alpha Desk']);
    Machine::factory()->create(['name' => 'Bravo Desk']);
    Machine::factory()->count(24)->create();
    Machine::factory()->create(['name' => 'Retired Desk', 'archived_at' => now()]);

    actingAs($this->admin);

    // Archived machines are out of the default set: 26 active, the 27th hidden.
    $all = manageListPartial('/admin/machines', 'Manage/Machines/Index');
    expect($all['rows'])->toHaveCount(26)
        ->and(collect($all['rows'])->pluck('cells.name'))->not->toContain('Retired Desk');

    $searched = manageListPartial('/admin/machines?search=Alpha+Desk', 'Manage/Machines/Index');
    expect($searched['rows'])->toHaveCount(1)
        ->and($searched['rows'][0]['cells']['name'])->toBe('Alpha Desk')
        ->and($searched['search'])->toBe('Alpha Desk');

    // The archived ternary is the one filter this resource declares, and turning it on
    // has to swap the set rather than widen it.
    $archived = manageListPartial('/admin/machines?filter[archived]=1', 'Manage/Machines/Index');
    expect($archived['rows'])->toHaveCount(1)
        ->and($archived['rows'][0]['cells']['name'])->toBe('Retired Desk')
        ->and(collect($archived['filters'])->firstWhere('key', 'archived')['value'])->toBe('1');

    /*
     * MachineResource ran ->paginated(false), which becomes perPage 200 with the options
     * [25, 50, 100, 200] (checklist 988). 10 is not offered here, so paging is exercised
     * at the smallest size this list actually accepts; asking for 10 has to fall back to
     * the declared 200 rather than silently page at 10.
     */
    $first = manageListPartial('/admin/machines?per_page=25&page=1', 'Manage/Machines/Index');
    $second = manageListPartial('/admin/machines?per_page=25&page=2', 'Manage/Machines/Index');

    expect($first['rows'])->toHaveCount(25)
        ->and($second['rows'])->toHaveCount(1)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);

    expect(manageListPartial('/admin/machines?per_page=10', 'Manage/Machines/Index')['meta']['perPage'])->toBe(200);

    expect($all['sort'])->toBe(['key' => 'id', 'dir' => 'asc']);
});

test('the staff list sorts, searches, filters and paginates under a partial visit', function () {
    Staff::factory()->create(['name' => 'Alpha', 'last_login_at' => now()->subDays(9)]);
    Staff::factory()->create(['name' => 'Zulu', 'last_login_at' => now()->subDay()]);
    Staff::factory()->count(10)->create(['is_active' => false]);

    actingAs($this->admin);

    expect(manageListPartial('/admin/staff', 'Manage/Staff/Index')['rows'])->toHaveCount(12);

    $searched = manageListPartial('/admin/staff?search=Alpha', 'Manage/Staff/Index');
    expect($searched['rows'])->toHaveCount(1)->and($searched['search'])->toBe('Alpha');

    $ascending = manageListPartial('/admin/staff?sort=last_login_at&dir=asc', 'Manage/Staff/Index');
    $descending = manageListPartial('/admin/staff?sort=last_login_at&dir=desc', 'Manage/Staff/Index');
    expect($ascending['sort'])->toBe(['key' => 'last_login_at', 'dir' => 'asc'])
        ->and($ascending['rows'][0]['id'])->not->toBe($descending['rows'][0]['id']);

    $active = manageListPartial('/admin/staff?filter[is_active]=1', 'Manage/Staff/Index');
    expect($active['rows'])->toHaveCount(2)
        ->and(collect($active['rows'])->pluck('cells.name')->sort()->values()->all())->toBe(['Alpha', 'Zulu']);

    $first = manageListPartial('/admin/staff?per_page=10&page=1', 'Manage/Staff/Index');
    $second = manageListPartial('/admin/staff?per_page=10&page=2', 'Manage/Staff/Index');
    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);
});

test('the sumup readers list paginates under a partial visit and still carries all five keys', function () {
    // The observer calls the SumUp merchant API on write; fixtures are built without
    // events so nothing outbound happens and remote_id stays as declared.
    Http::fake(['*' => Http::response(['id' => 'rdr_FROM_SUMUP'], 200)]);

    $reader = fn (array $attributes) => SumUpReader::withoutEvents(fn () => SumUpReader::create($attributes));

    foreach (range(1, 12) as $index) {
        $reader(['name' => 'Cashdesk '.$index, 'remote_id' => 'rdr_'.$index, 'paring_code' => 'PAIR-'.$index]);
    }

    actingAs($this->admin);

    $first = manageListPartial('/admin/sumup-readers?per_page=10&page=1', 'Manage/SumUpReaders/Index');
    $second = manageListPartial('/admin/sumup-readers?per_page=10&page=2', 'Manage/SumUpReaders/Index');

    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);

    // SumUpReaderResource declares `->filters([ // ])` and no searchable column, so both
    // keys are empty here on purpose; the envelope still has to carry them.
    expect($first['filters'])->toBe([])
        ->and($first['search'])->toBe('')
        ->and($first['sort'])->toBe(['key' => 'id', 'dir' => 'asc']);
});

test('the tse clients list searches and paginates under a partial visit', function () {
    // The observer PUTs the client to Fiskaly on created; no API key is configured under
    // phpunit, so the service returns before any call, and the fake is belt and braces.
    Http::fake();

    foreach (range(1, 12) as $index) {
        TseClient::create([
            'remote_id' => 'fiskaly-client-'.$index,
            'serial_number' => 'TSE-SERIAL-'.$index,
            'state' => TseClientStateEnum::REGISTERED,
        ]);
    }

    actingAs($this->admin);

    $searched = manageListPartial('/admin/tse-clients?search=TSE-SERIAL-7', 'Manage/TseClients/Index');
    expect($searched['rows'])->toHaveCount(1)
        ->and($searched['rows'][0]['cells']['remote_id'])->toBe('fiskaly-client-7')
        ->and($searched['search'])->toBe('TSE-SERIAL-7');

    $first = manageListPartial('/admin/tse-clients?per_page=10&page=1', 'Manage/TseClients/Index');
    $second = manageListPartial('/admin/tse-clients?per_page=10&page=2', 'Manage/TseClients/Index');

    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);

    // TseClientResource declares `->filters([])` and no sortable column, so a requested
    // sort has to come back as the declared default rather than reordering the list.
    expect($first['filters'])->toBe([])
        ->and(manageListPartial('/admin/tse-clients?sort=serial_number&dir=desc', 'Manage/TseClients/Index')['sort'])
        ->toBe(['key' => 'id', 'dir' => 'asc']);
});

test('the printers list searches and paginates under a partial visit', function () {
    Printer::factory()->create(['name' => 'Alpha Zebra']);
    Printer::factory()->count(11)->create();

    actingAs($this->admin);

    $all = manageListPartial('/admin/printers', 'Manage/Printers/Index');
    expect($all['rows'])->toHaveCount(12);

    $searched = manageListPartial('/admin/printers?search=Alpha+Zebra', 'Manage/Printers/Index');
    expect($searched['rows'])->toHaveCount(1)
        ->and($searched['rows'][0]['cells']['name'])->toBe('Alpha Zebra')
        ->and($searched['search'])->toBe('Alpha Zebra');

    $first = manageListPartial('/admin/printers?per_page=10&page=1', 'Manage/Printers/Index');
    $second = manageListPartial('/admin/printers?per_page=10&page=2', 'Manage/Printers/Index');

    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);

    // PrinterResource declares none, and none were added.
    expect($all['filters'])->toBe([])->and($all['sort'])->toBe(['key' => 'id', 'dir' => 'asc']);
});

test('the print jobs list sorts, searches, filters and paginates under a partial visit', function () {
    $alpha = Printer::factory()->create(['name' => 'Alpha Printer']);
    $zulu = Printer::factory()->create(['name' => 'Zulu Printer']);

    PrintJob::factory()->create(['printer_id' => $alpha->id, 'priority' => 1]);
    PrintJob::factory()->failed()->create(['printer_id' => $zulu->id, 'priority' => 9]);
    PrintJob::factory()->count(10)->create(['printer_id' => $zulu->id]);

    actingAs($this->admin);

    expect(manageListPartial('/admin/print-jobs', 'Manage/PrintJobs/Index')['rows'])->toHaveCount(12);

    // `printer_name` is searched through the relation, not the row array.
    $searched = manageListPartial('/admin/print-jobs?search=Alpha+Printer', 'Manage/PrintJobs/Index');
    expect($searched['rows'])->toHaveCount(1)
        ->and($searched['rows'][0]['cells']['printer_name'])->toBe('Alpha Printer');

    $ascending = manageListPartial('/admin/print-jobs?sort=priority&dir=asc', 'Manage/PrintJobs/Index');
    $descending = manageListPartial('/admin/print-jobs?sort=priority&dir=desc', 'Manage/PrintJobs/Index');
    expect($ascending['sort'])->toBe(['key' => 'priority', 'dir' => 'asc'])
        ->and($ascending['rows'][0]['id'])->not->toBe($descending['rows'][0]['id']);

    // The printer scope is an ordinary filter now rather than the old resource-wide
    // `?printer=` query scope (plan 2.3, audit 88).
    $byPrinter = manageListPartial('/admin/print-jobs?filter[printer]='.$alpha->id, 'Manage/PrintJobs/Index');
    expect($byPrinter['rows'])->toHaveCount(1)
        ->and(collect($byPrinter['filters'])->firstWhere('key', 'printer')['value'])->toBe((string) $alpha->id);

    $failed = manageListPartial(
        '/admin/print-jobs?filter[status]='.PrintJobStatusEnum::Failed->value,
        'Manage/PrintJobs/Index'
    );
    expect($failed['rows'])->toHaveCount(1);

    $first = manageListPartial('/admin/print-jobs?per_page=10&page=1', 'Manage/PrintJobs/Index');
    $second = manageListPartial('/admin/print-jobs?per_page=10&page=2', 'Manage/PrintJobs/Index');
    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);
});

test('the print batches list sorts, searches, filters and paginates under a partial visit', function () {
    $alpha = Printer::factory()->create(['name' => 'Alpha Printer']);
    $zulu = Printer::factory()->create(['name' => 'Zulu Printer']);

    PrintBatch::factory()->create(['name' => 'Alpha run', 'printer_id' => $alpha->id]);
    PrintBatch::factory()->paused()->create(['name' => 'Zulu run', 'printer_id' => $zulu->id]);
    PrintBatch::factory()->count(10)->create(['printer_id' => $zulu->id]);

    actingAs($this->admin);

    expect(manageListPartial('/admin/print-batches', 'Manage/PrintBatches/Index')['rows'])->toHaveCount(12);

    $searched = manageListPartial('/admin/print-batches?search=Alpha+run', 'Manage/PrintBatches/Index');
    expect($searched['rows'])->toHaveCount(1)
        ->and($searched['rows'][0]['cells']['name'])->toBe('Alpha run');

    // `printer_name` is searched and sorted through the relation, not the row array.
    $byPrinter = manageListPartial('/admin/print-batches?search=Alpha+Printer', 'Manage/PrintBatches/Index');
    expect($byPrinter['rows'])->toHaveCount(1);

    $ascending = manageListPartial('/admin/print-batches?sort=name&dir=asc', 'Manage/PrintBatches/Index');
    $descending = manageListPartial('/admin/print-batches?sort=name&dir=desc', 'Manage/PrintBatches/Index');
    expect($ascending['sort'])->toBe(['key' => 'name', 'dir' => 'asc'])
        ->and($ascending['rows'][0]['id'])->not->toBe($descending['rows'][0]['id']);

    // The status filter is the only multi-select in the printing slice.
    $paused = manageListPartial(
        '/admin/print-batches?filter[status][]='.PrintBatchStatusEnum::Paused->value,
        'Manage/PrintBatches/Index'
    );
    expect($paused['rows'])->toHaveCount(1)
        ->and($paused['rows'][0]['cells']['name'])->toBe('Zulu run');

    $first = manageListPartial('/admin/print-batches?per_page=10&page=1', 'Manage/PrintBatches/Index');
    $second = manageListPartial('/admin/print-batches?per_page=10&page=2', 'Manage/PrintBatches/Index');
    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);
});

/*
 * The batch detail page carries a second list envelope, for the cards in the run, and it
 * polls. It is the only page in the panel where an envelope sits under a record rather than
 * at a module root, so the same partial visit is proved against it.
 */
test('the batch card list sorts and paginates under a partial visit', function () {
    $batch = PrintBatch::factory()->printing()->create();

    foreach (range(1, 12) as $sequence) {
        PrintJob::factory()->create(['print_batch_id' => $batch->id, 'sequence' => $sequence]);
    }

    actingAs($this->admin);

    $url = '/admin/print-batches/'.$batch->id;

    $ascending = manageListPartial($url, 'Manage/PrintBatches/Show');
    expect($ascending['sort'])->toBe(['key' => 'sequence', 'dir' => 'asc'])
        ->and($ascending['rows'][0]['cells']['sequence'])->toBe(1);

    $descending = manageListPartial($url.'?sort=sequence&dir=desc', 'Manage/PrintBatches/Show');
    expect($descending['rows'][0]['cells']['sequence'])->toBe(12);

    $second = manageListPartial($url.'?per_page=10&page=2', 'Manage/PrintBatches/Show');
    expect($second['rows'])->toHaveCount(2)->and($second['meta']['page'])->toBe(2);
});

test('the checkouts list sorts, searches, filters and paginates under a partial visit', function () {
    $desk = Machine::factory()->create(['name' => 'Cashdesk 1']);
    $checkout = function (array $attributes) use ($desk) {
        return Checkout::create([
            'status' => CheckoutFinished::class,
            'payment_method' => 'cash',
            'user_id' => User::factory()->create()->id,
            'machine_id' => $desk->id,
            'subtotal' => 1000,
            'tax' => 190,
            'total' => 1190,
            'fiskaly_data' => [],
            ...$attributes,
        ]);
    };

    $checkout(['user_id' => User::factory()->create(['name' => 'Alpha Buyer'])->id, 'total' => 100]);
    $checkout(['status' => CheckoutCancelled::class, 'payment_method' => 'card', 'total' => 200]);

    foreach (range(1, 10) as $index) {
        $checkout(['total' => 300]);
    }

    actingAs($this->admin);

    $all = manageListPartial('/admin/checkouts', 'Manage/Checkouts/Index');
    expect($all['rows'])->toHaveCount(12)
        ->and($all['sort'])->toBe(['key' => 'created_at', 'dir' => 'desc']);

    // `user_name` is searched through the relation, not the row array.
    $searched = manageListPartial('/admin/checkouts?search=Alpha+Buyer', 'Manage/Checkouts/Index');
    expect($searched['rows'])->toHaveCount(1)
        ->and($searched['rows'][0]['cells']['user_name']['display'])->toBe('Alpha Buyer');

    $ascending = manageListPartial('/admin/checkouts?sort=total&dir=asc', 'Manage/Checkouts/Index');
    $descending = manageListPartial('/admin/checkouts?sort=total&dir=desc', 'Manage/Checkouts/Index');
    expect($ascending['sort'])->toBe(['key' => 'total', 'dir' => 'asc'])
        ->and($ascending['rows'][0]['cells']['total'])->toBe('€1.00')
        ->and($descending['rows'][0]['cells']['total'])->toBe('€3.00');

    /*
     * The status filter is the one this module exists to fix: Filament keyed its options
     * by FQCN while the column holds the states' own `$name` strings, so it matched zero
     * rows (audit landmine 6). Multi-select, as the resource declares it.
     */
    $cancelled = manageListPartial('/admin/checkouts?filter[status][]=CANCELLED', 'Manage/Checkouts/Index');
    expect($cancelled['rows'])->toHaveCount(1)
        ->and($cancelled['rows'][0]['cells']['status']['label'])->toBe('Cancelled');

    // The Sum summariser rides inside `meta` so it is reloaded with the rows it totals.
    expect($all['meta']['summary']['value'])->toBe('€33.00')
        ->and($cancelled['meta']['summary']['value'])->toBe('€2.00');

    $first = manageListPartial('/admin/checkouts?per_page=10&page=1', 'Manage/Checkouts/Index');
    $second = manageListPartial('/admin/checkouts?per_page=10&page=2', 'Manage/Checkouts/Index');
    expect($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);
});

/*
 * The checkout detail page carries the second list envelope in this batch, for the items on
 * the sale. Same shape as the batch card list: an envelope under a record rather than at a
 * module root, so the same partial visit is proved against it.
 */
test('the checkout items list searches and paginates under a partial visit', function () {
    $checkout = Checkout::create([
        'status' => CheckoutFinished::class,
        'payment_method' => 'cash',
        'user_id' => User::factory()->create()->id,
        'machine_id' => Machine::factory()->create()->id,
        'subtotal' => 1000,
        'tax' => 190,
        'total' => 1190,
        'fiskaly_data' => [],
    ]);

    foreach (range(1, 26) as $index) {
        $checkout->items()->create([
            'name' => $index === 1 ? 'Alpha line' : 'Line '.$index,
            'description' => [],
            // `payable_type` is NOT NULL; the desk machine is the cheap non-badge target.
            'payable_type' => Machine::class,
            'payable_id' => $checkout->machine_id,
            'subtotal' => 100,
            'tax' => 19,
            'total' => 119,
        ]);
    }

    actingAs($this->admin);

    $url = '/admin/checkouts/'.$checkout->id;

    $all = manageListPartial($url, 'Manage/Checkouts/Show');
    expect($all['rows'])->toHaveCount(26)
        ->and($all['rows'][0]['cells']['total'])->toBe('€1.19')
        // ->paginated(false) becomes perPage 200 with the pager visible (plan 2.3).
        ->and($all['meta']['perPage'])->toBe(200);

    $searched = manageListPartial($url.'?search=Alpha+line', 'Manage/Checkouts/Show');
    expect($searched['rows'])->toHaveCount(1)->and($searched['search'])->toBe('Alpha line');

    /*
     * 10 is not one of this table's per-page options, so it falls back to the declared 200
     * rather than silently paging at 10; paging is exercised at 25, the smallest size the
     * pager actually offers.
     */
    expect(manageListPartial($url.'?per_page=10', 'Manage/Checkouts/Show')['meta']['perPage'])->toBe(200);

    $first = manageListPartial($url.'?per_page=25&page=1', 'Manage/Checkouts/Show');
    $second = manageListPartial($url.'?per_page=25&page=2', 'Manage/Checkouts/Show');
    expect($first['rows'])->toHaveCount(25)
        ->and($second['rows'])->toHaveCount(1)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id']);
});

/*
 * The one check that cannot be deferred to review. Three of these lists sit on top of a
 * credential each, and all three leaked one in Filament: SumUpReaderResource rendered the
 * pairing code as a plain column (audit landmine 10), StaffResource carried the PIN into
 * the payload behind a formatted cell, and the machine login link was a URL that logs a
 * till in. Each module asserts its own; this asserts all three against the whole serialised
 * list payload at once, so a credential reintroduced through a shared prop - a page action,
 * a row action URL, a filter option - is caught even when the cell itself stays clean.
 */
test('no list payload carries a PIN, an RFID tag, a pairing code or a login link', function () {
    Http::fake(['*' => Http::response(['id' => 'rdr_FROM_SUMUP'], 200)]);

    $pin = '493071';
    $tag = 'RFIDTAGCONTENT-Z9X8W7';
    $pairing = 'PAIRCODE-Z9X8W7';

    $member = Staff::factory()->create(['name' => 'Desk Lead', 'pin_code' => $pin]);
    $member->rfidTags()->create(['content' => $tag, 'name' => 'Blue fob', 'is_active' => true]);

    SumUpReader::withoutEvents(fn () => SumUpReader::create([
        'name' => 'Cashdesk 1',
        'remote_id' => 'rdr_ABC123',
        'paring_code' => $pairing,
    ]));

    Machine::factory()->create(['name' => 'Front Desk']);

    actingAs($this->admin);

    $lists = [
        '/admin/machines' => 'Manage/Machines/Index',
        '/admin/staff' => 'Manage/Staff/Index',
        '/admin/sumup-readers' => 'Manage/SumUpReaders/Index',
    ];

    // Both shapes: the full page load, and the partial visit the table makes. The full
    // loads run first as a block, because withHeaders() sticks to the test instance and a
    // plain get() after a partial visit would be answered as Inertia JSON.
    $payloads = [];

    foreach (array_keys($lists) as $url) {
        $payloads[] = json_encode(get($url)->viewData('page')['props']);
    }

    foreach ($lists as $url => $component) {
        $payloads[] = json_encode(manageListPartial($url, $component));
    }

    foreach ($payloads as $payload) {
        expect($payload)->not->toContain($pin)
            ->and($payload)->not->toContain($tag)
            ->and($payload)->not->toContain($pairing)
            // A login link is minted by POSTing to the endpoint. The list may name the
            // endpoint; it must never carry a signed URL, which is what `signature=`
            // marks.
            ->and($payload)->not->toContain('signature=')
            ->and($payload)->not->toContain('/pos/auth/machine-login');
    }

    /*
     * Non-vacuity. Every assertion above is a `not`, so it would also pass against three
     * empty lists or three lists that dropped the columns entirely. These three say the
     * fixtures really are on the page and each secret was replaced rather than removed:
     * the PIN is the two literal words, the tag is counted without its content, and the
     * pairing code is the mask.
     */
    $staffRow = collect(manageListPartial('/admin/staff', 'Manage/Staff/Index')['rows'])
        ->firstWhere('cells.name', 'Desk Lead');

    expect($staffRow['cells']['pin_code'])->toBe('Set')
        ->and($staffRow['cells']['rfid_tags_count'])->toBe(1);

    $readerRow = manageListPartial('/admin/sumup-readers', 'Manage/SumUpReaders/Index')['rows'][0];

    expect($readerRow['cells']['name'])->toBe('Cashdesk 1')
        ->and($readerRow['cells']['paring_code']['display'])->not->toBe($pairing)
        ->and($readerRow['cells']['paring_code']['display'])->not->toBe('');
});

test('the sidebar carries every module registered so far', function () {
    actingAs($this->admin);

    $groups = get(route('manage.dashboard'))->viewData('page')['props']['manageNav'];
    $labels = collect($groups)->flatMap(fn (array $group) => collect($group['items'])->pluck('label'))->all();

    expect($labels)->toContain('Dashboard', 'Events', 'Badges', 'Fursuits', 'Special Codes', 'Users', 'Badge Preview');

    // Phase 5 and 6. Navigation drops any item whose route does not exist, so this is the
    // only place that can say all five modules registered theirs.
    expect($labels)->toContain('Machines', 'Staff', 'SumUp Readers', 'Printers', 'Print Jobs');

    // Phase 7.
    expect($labels)->toContain('Print Batches');

    // Phase 8.
    expect($labels)->toContain('TSE Clients');
    expect($labels)->toContain('Checkouts');

    // Phase 9. Both routes now exist, so Navigation stops dropping them.
    expect($labels)->toContain('PDF Generator');
    expect($labels)->toContain('DB Service');

    /*
     * Every module in Navigation has now shipped, so there is no pending item left to
     * assert the drop against. The drop is still load-bearing, so the case below covers
     * it with the one item that is conditionally absent: DB Service is gated on
     * `manage-admin`, and a reviewer must not be offered a page that moves money.
     *
     * Asserted one at a time when a pending list returns: `not->toContain($a, $b)` passes
     * as soon as a single argument is absent, so the list form kept passing after
     * Printers and Print Jobs shipped.
     */
});

test('the rail hides DB Service from a reviewer who may not run it', function () {
    $reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    actingAs($reviewer);

    $groups = get(route('manage.dashboard'))->viewData('page')['props']['manageNav'];
    $labels = collect($groups)->flatMap(fn (array $group) => collect($group['items'])->pluck('label'))->all();

    // Non-vacuity: the reviewer really does get a rail, and it really does lose this item.
    expect($labels)->toContain('Badge Preview', 'PDF Generator')
        ->and($labels)->not->toContain('DB Service');
});

test('the rail counts follow the selected event', function () {
    $species = Species::factory()->create(['name' => 'Wolf']);
    $other = Event::factory()->create(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);

    $pending = function (Event $event) use ($species) {
        Fursuit::factory()->create([
            'event_id' => $event->id,
            'species_id' => $species->id,
            'user_id' => User::factory()->create()->id,
            'status' => Pending::class,
        ]);
    };

    $pending($this->event);
    $pending($this->event);
    $pending($other);

    $countFor = function (?int $eventId) {
        actingAs($this->admin);
        session([EventScope::SESSION_ID => $eventId, EventScope::SESSION_CHOSEN => true]);

        $props = get(route('manage.dashboard'))->viewData('page')['props'];

        $fursuits = collect($props['manageNav'])
            ->flatMap(fn (array $group) => $group['items'])
            ->firstWhere('label', 'Fursuits');

        return [$fursuits['badge']['label'] ?? null, $props['manageStrip']['segments'][0]['value']];
    };

    expect($countFor($this->event->id))->toBe(['2', 2])
        ->and($countFor($other->id))->toBe(['1', 1]);
});
