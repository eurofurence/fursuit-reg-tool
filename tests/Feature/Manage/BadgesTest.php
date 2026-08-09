<?php

/*
 * Badges.
 *
 * The biggest resource in the old panel and the one carrying the money. Four things get
 * more attention than the rest, because all four are broken today:
 *
 *  - the Total column rendered 100x too high and the Total form field turned
 *    300 cents into 3 on an unchanged save,
 *  - the attendee-id sort and both halves of the attendee range used
 *    CAST(x AS UNSIGNED), which does not exist on the SQLite database this suite runs on
 *   ,
 *  - the two status selects wrote raw state strings and skipped every transition side
 *    effect,
 *  - a badge whose fursuit or user is soft-deleted took the whole table down.
 *
 * The rest is parity, transcribed from the audit: fourteen columns in order, four filters,
 * plus what was added afterwards - the Verified column and the print_verified filter that
 * the POS check-off screen is read through.
 * five form sections, and the old panel's own delete copy.
 */

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use App\Policies\BadgePolicy;
use App\Support\Manage\Action;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

beforeEach(function () {
    // The image column reads the private s3 disk, as the old badge list's ImageColumn does.
    Storage::fake('s3');

    $this->event = Event::factory()->create(['name' => 'Eurofurence 29', 'starts_at' => now()->addDays(30)]);
    $this->otherEvent = Event::factory()->create(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->nobody = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    /*
     * One badge, with everything the list reads across four relations: fursuit, species,
     * owner and the owner's event_users row, which is where the attendee id lives.
     */
    $this->badge = function (array $attributes = [], array $fursuit = [], ?string $attendeeId = '0142', ?Event $event = null) {
        $event ??= $this->event;
        $owner = User::factory()->create();

        if ($attendeeId !== null) {
            EventUser::factory()->create([
                'user_id' => $owner->id,
                'event_id' => $event->id,
                'attendee_id' => $attendeeId,
            ]);
        }

        return Badge::factory()->create([
            'status_fulfillment' => 'pending',
            'status_payment' => 'unpaid',
            'extra_copy' => false,
            'extra_copy_of' => null,
            'is_free_badge' => false,
            'subtotal' => 252,
            'tax' => 48,
            'total' => 300,
            'printed_at' => null,
            'picked_up_at' => null,
            'ready_for_pickup_at' => null,
            'fursuit_id' => Fursuit::factory()->create([
                'event_id' => $event->id,
                'user_id' => $owner->id,
                // `species.name` is unique, so every badge in a case shares one row.
                'species_id' => Species::firstOrCreate(['name' => 'Blue Fox'], ['type' => 'canine', 'checked' => true])->id,
                'name' => 'Nibbles',
                ...$fursuit,
            ])->id,
            ...$attributes,
        ]);
    };

    // Every read below states which event scope it is asking for, rather than inheriting
    // whatever the seeder picked.
    $this->scoped = fn (?int $eventId) => actingAs($this->admin)->withSession([
        EventScope::SESSION_ID => $eventId,
        EventScope::SESSION_CHOSEN => true,
    ]);
});

test('the list renders the fifteen columns in order, with their labels and types', function () {
    ($this->badge)();

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Badges/Index')
            ->where('columns.0', fn ($c) => $c['key'] === 'fursuit.image' && $c['label'] === 'Image' && $c['type'] === 'image')
            ->where('columns.1', fn ($c) => $c['key'] === 'fursuit.name' && $c['label'] === 'Fursuit' && $c['sortable'])
            ->where('columns.2', fn ($c) => $c['key'] === 'fursuit.species.name' && $c['label'] === 'Species' && $c['toggleable'] && ! $c['hiddenByDefault'])
            ->where('columns.3', fn ($c) => $c['key'] === 'fursuit.user.name' && $c['label'] === 'Owner' && $c['toggleable'] && ! $c['hiddenByDefault'])
            ->where('columns.4', fn ($c) => $c['key'] === 'custom_id' && $c['label'] === 'Badge ID' && $c['type'] === 'copyable' && $c['toggleable'] && ! $c['hiddenByDefault'])
            ->where('columns.5', fn ($c) => $c['key'] === 'sort_attendee_id' && $c['label'] === 'Attendee ID' && $c['sortable'] && $c['fallback'] === 'N/A')
            ->where('columns.6', fn ($c) => $c['key'] === 'print_jobs_count' && $c['label'] === 'Print Jobs' && $c['type'] === 'badge' && $c['align'] === 'center')
            ->where('columns.7', fn ($c) => $c['key'] === 'status_fulfillment' && $c['label'] === 'Fulfillment' && $c['type'] === 'badge')
            ->where('columns.8', fn ($c) => $c['key'] === 'status_payment' && $c['label'] === 'Payment' && $c['type'] === 'badge')
            ->where('columns.9', fn ($c) => $c['key'] === 'extra_copy' && $c['label'] === 'Extra Copy' && $c['hiddenByDefault'])
            ->where('columns.10', fn ($c) => $c['key'] === 'total' && $c['label'] === 'Total' && $c['type'] === 'money' && $c['align'] === 'right' && $c['hiddenByDefault'])
            ->where('columns.11', fn ($c) => $c['key'] === 'created_at' && $c['label'] === 'Created' && $c['hiddenByDefault'])
            ->where('columns.12', fn ($c) => $c['key'] === 'printed_at' && $c['label'] === 'Printed At' && $c['fallback'] === 'Not printed' && $c['hiddenByDefault'])
            ->where('columns.13', fn ($c) => $c['key'] === 'picked_up_at' && $c['label'] === 'Picked Up' && $c['fallback'] === 'Not picked up' && $c['hiddenByDefault'])
            // Column 15 is not the audit's: it is the desk check-off, added with the POS
            // verification screen so the printed-but-never-seen cards can be listed.
            ->where('columns.14', fn ($c) => $c['key'] === 'verified_print_at' && $c['label'] === 'Verified' && $c['fallback'] === 'Not verified' && $c['hiddenByDefault'])
            ->count('columns', 15)
            // The five isToggledHiddenByDefault: true flags from the audit, plus Verified.
            ->where('hiddenColumns', ['extra_copy', 'total', 'created_at', 'printed_at', 'picked_up_at', 'verified_print_at'])
        );
});

test('the Total column renders euros from cents instead of a hundredfold', function () {
    // audit 1: ->money('EUR') with no divideBy on a cents column, so 300 cents rendered
    // as EUR 300.00. Column::money always divides; there is no undivided variant.
    ($this->badge)(['total' => 300]);

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.cells.total', '€3.00'));
});

test('the list opens sorted by attendee id numerically, on SQLite', function () {
    // audit 16 and 17: CAST(x AS UNSIGNED) is MySQL-only and the direction was
    // interpolated into orderByRaw. Lexicographically '1000' sorts before '9', so a
    // string sort and a numeric sort disagree on exactly this data.
    $ninth = ($this->badge)([], [], '9');
    $thousandth = ($this->badge)([], [], '1000');
    $first = ($this->badge)([], [], '0001');

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', ['key' => 'sort_attendee_id', 'dir' => 'asc'])
            ->where('rows.0.id', $first->id)
            ->where('rows.1.id', $ninth->id)
            ->where('rows.2.id', $thousandth->id)
        );
});

test('the attendee-id sort flips through the partial reload the client actually sends', function () {
    // useTableQuery visits with only=[rows,meta,filters,sort,search] and Inertia resolves
    // those by top-level key. A nested envelope answers all five with null, the client
    // merges the nulls over live props, and every sort, page and per-page click silently
    // changes nothing but the URL.
    $ninth = ($this->badge)([], [], '9');
    $thousandth = ($this->badge)([], [], '1000');

    $version = app(HandleInertiaRequests::class)->version(request());

    $partial = fn (array $query) => ($this->scoped)(null)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Manage/Badges/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
        ])
        ->get(route('admin.badges.index', $query));

    $descending = $partial(['sort' => 'sort_attendee_id', 'dir' => 'desc'])->assertSuccessful();

    expect($descending->json('props.sort'))->toBe(['key' => 'sort_attendee_id', 'dir' => 'desc'])
        ->and($descending->json('props.rows.0.id'))->toBe($thousandth->id)
        // The five requested keys have to carry data, not null.
        ->and($descending->json('props.meta.page'))->toBe(1)
        ->and($descending->json('props.search'))->toBe('')
        ->and($descending->json('props.filters'))->toHaveCount(7);

    expect($partial(['sort' => 'sort_attendee_id', 'dir' => 'asc'])->json('props.rows.0.id'))->toBe($ninth->id);
});

test('a badge with no attendee id renders N/A rather than an empty cell', function () {
    ($this->badge)([], [], null);

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.cells.sort_attendee_id', null)
            ->where('columns.5.fallback', 'N/A')
        );
});

test('the Print Jobs cell carries the audit state string and the colour ladder', function () {
    $badge = ($this->badge)();
    $printer = Printer::factory()->create();

    $job = fn (PrintJobStatusEnum $status) => PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'printable_type' => Badge::class,
        'printable_id' => $badge->id,
        'status' => $status,
    ]);

    $cell = fn () => ($this->scoped)(null)->get(route('admin.badges.index'))
        ->viewData('page')['props']['rows'][0]['cells']['print_jobs_count'];

    expect($cell())->toMatchArray(['label' => '0', 'tone' => 'idle']);

    $job(PrintJobStatusEnum::Printed);
    expect($cell())->toMatchArray(['label' => '1', 'tone' => 'ok']);

    $job(PrintJobStatusEnum::Queued);
    expect($cell())->toMatchArray(['label' => '2 (1 pending)', 'tone' => 'info']);

    $job(PrintJobStatusEnum::Failed);
    expect($cell())->toMatchArray(['label' => '3 (1 failed)', 'tone' => 'warn']);
});

test('the Print Jobs counts cost the same number of queries however many rows there are', function () {
    // audit 95: `$record->printJobs()->get()` ran twice per row, on a table polling every
    // 5 seconds. Three correlated counts ride along with the list query instead.
    $count = function () {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'print_jobs')) {
                $queries++;
            }
        });

        ($this->scoped)(null)->get(route('admin.badges.index'))->assertSuccessful();

        return $queries;
    };

    ($this->badge)();

    // One warm-up first: the rail caches its own counts for 5 seconds, so a cold request
    // and a warm one are not comparable and neither says anything about the row cost.
    ($this->scoped)(null)->get(route('admin.badges.index'))->assertSuccessful();

    $withOne = $count();

    foreach (range(1, 5) as $ignored) {
        ($this->badge)();
    }

    // Whatever the fixed cost of the page is (the rail's own counts are in this number
    // too), it must not grow with the number of rows. Two reads per row is what this
    // replaces.
    expect($count())->toBe($withOne)
        ->and($withOne)->toBeLessThan(6);
});

test('the two status cells carry the audit labels', function () {
    ($this->badge)(['status_fulfillment' => 'ready_for_pickup', 'status_payment' => 'paid']);

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.cells.status_fulfillment.label', 'Ready for Pickup')
            ->where('rows.0.cells.status_payment.label', 'Paid')
        );
});

test('a badge whose fursuit is soft-deleted still renders its row', function () {
    // audit 113: `$record->fursuit->id` and `$record->fursuit->user->name` with no null
    // guard, on a model using SoftDeletes, so one deleted fursuit threw while rendering
    // and took the whole table down.
    $badge = ($this->badge)();

    // Written straight to the column: FursuitObserver cascades a delete to the fursuit's
    // badges, and the row this has to survive is one whose fursuit went without it.
    DB::table('fursuits')->where('id', $badge->fursuit_id)->update(['deleted_at' => now()]);

    $response = ($this->scoped)(null)->get(route('admin.badges.index'))->assertSuccessful();

    // Read off the response rather than through assertInertia: these cell keys contain
    // dots of their own, so a dotted assertion path cannot address them.
    $row = $response->viewData('page')['props']['rows'][0];

    expect($row['id'])->toBe($badge->id)
        ->and($row['cells']['fursuit.name'])->toBeNull()
        ->and($row['cells']['fursuit.user.name'])->toBeNull()
        ->and($row['cells']['fursuit.species.name'])->toBeNull();
});

test('the Owner cell links at the users list pre-filtered by that name', function () {
    $badge = ($this->badge)();
    $owner = $badge->fursuit->user;

    $row = ($this->scoped)(null)->get(route('admin.badges.index'))
        ->viewData('page')['props']['rows'][0];

    // The Fursuit cell is plain text until phase 3 registers the fursuit view page, and a
    // link from the moment it does; both shapes carry the same display value.
    $fursuit = $row['cells']['fursuit.name'];

    expect($row['cells']['fursuit.user.name'])->toBe([
        'display' => $owner->name,
        'url' => route('admin.settings.users.index', ['search' => $owner->name]),
    ])
        ->and(is_array($fursuit) ? $fursuit['display'] : $fursuit)->toBe('Nibbles')
        ->and($row['cells']['fursuit.species.name'])->toBe('Blue Fox')
        // The image column reads the private s3 disk, and a fursuit with no image gets
        // the old badge list's defaultImageUrl rather than a broken cell.
        ->and($row['cells']['fursuit.image'])->toBeString();
});

test('the list declares its filters with their labels, placeholders and option sets', function () {
    ($this->badge)();

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('filters', 7)
            ->where('filters.0.key', 'status_fulfillment')
            ->where('filters.0.label', 'Fulfillment Status')
            ->where('filters.0.placeholder', 'All Statuses')
            ->where('filters.0.multiple', true)
            ->where('filters.0.options', [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'processing', 'label' => 'Processing'],
                ['value' => 'ready_for_pickup', 'label' => 'Ready for Pickup'],
                ['value' => 'picked_up', 'label' => 'Picked Up'],
            ])
            ->where('filters.1.key', 'status_payment')
            ->where('filters.1.label', 'Payment Status')
            ->where('filters.1.placeholder', 'All Payments')
            ->where('filters.1.multiple', true)
            ->where('filters.1.options', [
                ['value' => 'unpaid', 'label' => 'Unpaid'],
                ['value' => 'paid', 'label' => 'Paid'],
            ])
            ->where('filters.2.key', 'is_free_badge')
            ->where('filters.2.type', 'ternary')
            ->where('filters.2.label', 'Free Badge')
            ->where('filters.2.placeholder', 'All Badges')
            ->where('filters.2.trueLabel', 'Free Badges Only')
            ->where('filters.2.falseLabel', 'Paid Badges Only')
            ->where('filters.3.key', 'attendee_id_range')
            ->where('filters.3.type', 'range')
            // No label is set in the old panel, so it renders its auto label.
            ->where('filters.3.label', 'Attendee id range')
            // The print cutoff, as two datetime bounds rather than dates: several runs go
            // out in a day and a date-only bound cannot separate them.
            ->where('filters.4.key', 'approved_from')
            ->where('filters.4.type', 'datetime')
            ->where('filters.4.label', 'Approved from')
            ->where('filters.4.chipLabel', 'Approved after')
            ->where('filters.5.key', 'approved_until')
            ->where('filters.5.type', 'datetime')
            ->where('filters.5.label', 'Approved until')
            ->where('filters.5.chipLabel', 'Approved before')
            // Not the audit's either: the reprint list. Printed, and never checked off
            // at the desk or seen by the print agent's camera.
            ->where('filters.6.key', 'print_verified')
            ->where('filters.6.type', 'ternary')
            ->where('filters.6.label', 'Print Verified')
            ->where('filters.6.placeholder', 'Verified or not')
            ->where('filters.6.trueLabel', 'Verified')
            ->where('filters.6.falseLabel', 'Not verified')
            // Nothing is filtered on first load; every filter opens blank.
            ->where('filters.0.value', [])
            ->where('filters.1.value', [])
            ->where('filters.2.value', '')
            ->where('filters.3.value', ['min' => '', 'max' => ''])
            ->where('filters.4.value', '')
            ->where('filters.5.value', '')
            ->where('filters.6.value', '')
        );
});

test('the approval cutoff narrows to badges approved inside the bound', function () {
    $early = ($this->badge)();
    $late = ($this->badge)();

    $early->fursuit->update(['approved_at' => now()->subHours(3)]);
    $late->fursuit->update(['approved_at' => now()->subMinutes(5)]);

    $version = app(HandleInertiaRequests::class)->version(request());

    $ids = fn (array $filter) => collect(
        ($this->scoped)(null)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
                'X-Inertia-Partial-Component' => 'Manage/Badges/Index',
                'X-Inertia-Partial-Data' => 'rows',
            ])
            ->get(route('admin.badges.index', ['filter' => $filter]))
            ->assertSuccessful()
            ->json('props.rows')
    )->pluck('id')->all();

    // The cutoff is what keeps a print run off the backlog it already printed.
    expect($ids(['approved_from' => now()->subHour()->format('Y-m-d\TH:i')]))->toBe([$late->id])
        ->and($ids(['approved_until' => now()->subHour()->format('Y-m-d\TH:i')]))->toBe([$early->id]);

    // A hand-edited bound that is not a datetime narrows nothing rather than throwing.
    expect($ids(['approved_from' => 'not-a-date']))->toHaveCount(2);
});

test('the fulfillment and payment filters narrow the row set', function () {
    $pending = ($this->badge)();
    $picked = ($this->badge)(['status_fulfillment' => 'picked_up', 'status_payment' => 'paid']);

    $rows = fn (array $query) => collect(
        ($this->scoped)(null)->get(route('admin.badges.index', $query))->viewData('page')['props']['rows']
    )->pluck('id')->all();

    expect($rows(['filter' => ['status_fulfillment' => ['picked_up']]]))->toBe([$picked->id])
        ->and($rows(['filter' => ['status_payment' => ['unpaid']]]))->toBe([$pending->id])
        ->and($rows(['filter' => ['status_fulfillment' => ['pending', 'picked_up']]]))->toHaveCount(2);
});

test('the free-badge ternary separates free from paid, and blank means all', function () {
    $free = ($this->badge)(['is_free_badge' => true]);
    $paid = ($this->badge)(['is_free_badge' => false]);

    $rows = fn (array $query) => collect(
        ($this->scoped)(null)->get(route('admin.badges.index', $query))->viewData('page')['props']['rows']
    )->pluck('id')->all();

    expect($rows(['filter' => ['is_free_badge' => '1']]))->toBe([$free->id])
        ->and($rows(['filter' => ['is_free_badge' => '0']]))->toBe([$paid->id])
        ->and($rows([]))->toHaveCount(2);
});

test('the attendee range filters numerically, on SQLite, and only within the selected event', function () {
    // audit 16 again: both halves of this filter were whereRaw CAST(x AS UNSIGNED).
    $low = ($this->badge)([], [], '9');
    $mid = ($this->badge)([], [], '500');
    $high = ($this->badge)([], [], '1000');

    $rows = fn (?int $eventId, array $range) => collect(
        ($this->scoped)($eventId)
            ->get(route('admin.badges.index', ['filter' => ['attendee_id_range' => $range]]))
            ->viewData('page')['props']['rows']
    )->pluck('id')->all();

    // Numeric, not lexicographic: '9' is inside 1..600 and '1000' is not.
    expect($rows($this->event->id, ['min' => '1', 'max' => '600']))->toBe([$low->id, $mid->id])
        ->and($rows($this->event->id, ['min' => '600']))->toBe([$high->id])
        ->and($rows($this->event->id, ['max' => '9']))->toBe([$low->id]);

    // A badge from another event is out of scope whatever the range says.
    $other = ($this->badge)([], [], '5', $this->otherEvent);

    expect($rows($this->event->id, ['min' => '1', 'max' => '600']))->not->toContain($other->id)
        ->and($rows(null, ['min' => '1', 'max' => '600']))->toContain($other->id);
});

test('search matches exactly the three fields the audit marks searchable', function () {
    $badge = ($this->badge)(['custom_id' => '0142-1'], ['name' => 'Nibbles']);
    $other = ($this->badge)(['custom_id' => '0999-1'], ['name' => 'Grimm']);
    $owner = $badge->fursuit->user;

    $rows = fn (string $term) => collect(
        ($this->scoped)(null)->get(route('admin.badges.index', ['search' => $term]))->viewData('page')['props']['rows']
    )->pluck('id')->all();

    expect($rows('Nibbles'))->toBe([$badge->id])
        ->and($rows('0142'))->toBe([$badge->id])
        // Owner is `fursuit.user.name`, two relations deep. Splitting that path at the
        // first dot handed `user.name` to where() as a column and 500'd the search.
        ->and($rows($owner->name))->toBe([$badge->id])
        // Species is not searchable in the audit, so it must not match.
        ->and($rows('Blue Fox'))->toBe([])
        ->and($rows('Grimm'))->toBe([$other->id]);
});

test('the list is scoped by the global event selector through the fursuit', function () {
    $mine = ($this->badge)();
    $theirs = ($this->badge)([], [], '0143', $this->otherEvent);

    ($this->scoped)($this->event->id)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 1)->where('rows.0.id', $mine->id));

    ($this->scoped)($this->otherEvent->id)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 1)->where('rows.0.id', $theirs->id));

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 2));
});

test('the table offers Edit and Print Badge and nothing else, and no create action at all', function () {
    // the old badge list passes bulkActions() an explicit array, so there is no bulk delete,
    // no export and no dissociate. Its one bulk action is printBadgeBulk, which shipped in
    // phase 7 with the rest of the print pipeline alongside the printBadge row action;
    // both are covered in full by BadgePrintTest, and asserted here only as the shape of
    // this table.
    ($this->badge)();

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('rows.0.actions', 2)
            ->where('rows.0.actions.0.name', 'edit')
            ->where('rows.0.actions.0.label', 'Edit')
            ->where('rows.0.actions.1.name', 'printBadge')
            ->count('bulkActions', 2)
            ->where('bulkActions.0.name', 'printBadgeBulk')
            ->where('bulkActions.1.name', 'setFulfillmentStatus')
            // The Create page is not ported: it has never been able to save.
            ->where('pageActions', fn ($actions) => ! collect($actions)->contains(fn ($action) => $action['name'] === 'create'))
        );
});

test('the pager offers 10 / 25 / 50 / 100 and no all', function () {
    ($this->badge)();

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.perPage', 25)
            ->where('meta.perPageOptions', [10, 25, 50, 100])
        );
});

test('the edit form ships the five sections, all read-only but the two statuses', function () {
    $badge = ($this->badge)(['custom_id' => '0142-1', 'total' => 300, 'subtotal' => 252, 'tax' => 48]);

    actingAs($this->admin)->get(route('admin.badges.edit', $badge))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Badges/Form')
            ->where('badge.fursuit', 'Nibbles')
            ->where('badge.custom_id', '0142-1')
            ->where('badge.species_name', 'Blue Fox')
            ->where('badge.owner_name', $badge->fursuit->user->name)
            // Read through the one money formatter, so the form and the column cannot
            // disagree about what a cents column means.
            ->where('badge.total', '€3.00')
            ->where('badge.subtotal', '€2.52')
            ->where('badge.tax', '€0.48')
            ->where('badge.is_free_badge', false)
            ->where('badge.extra_copy', false)
            ->where('badge.status_fulfillment', 'pending')
            ->where('badge.status_payment', 'unpaid')
            ->where('deleteAction.confirm.heading', 'Delete badge')
            ->where('deleteAction.confirm.description', Action::DEFAULT_CONFIRM_DESCRIPTION)
            ->where('deleteAction.confirm.submit', 'Delete')
        );
});

test('the status pickers offer only the transitions the state machine allows', function () {
    // audit 20: the Selects offered every state unconditionally and wrote the string
    // through the cast, so a badge could be put where no transition leads.
    $pending = ($this->badge)();

    actingAs($this->admin)->get(route('admin.badges.edit', $pending))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fulfillmentOptions', [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'processing', 'label' => 'Processing'],
            ])
            ->where('paymentOptions', [
                ['value' => 'unpaid', 'label' => 'Unpaid'],
                ['value' => 'paid', 'label' => 'Paid'],
            ])
        );

    // The POS error correction picked_up -> ready_for_pickup is a real transition and is
    // therefore offered.
    $picked = ($this->badge)(['status_fulfillment' => 'picked_up', 'status_payment' => 'paid']);

    actingAs($this->admin)->get(route('admin.badges.edit', $picked))
        ->assertInertia(fn (Assert $page) => $page
            ->where('fulfillmentOptions', [
                ['value' => 'picked_up', 'label' => 'Picked Up'],
                ['value' => 'ready_for_pickup', 'label' => 'Ready for Pickup'],
            ])
            // Paid is terminal: nothing transitions out of it.
            ->where('paymentOptions', [['value' => 'paid', 'label' => 'Paid']])
        );
});

test('saving a status runs the transition rather than writing the string', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)
        ->put(route('admin.badges.update', $badge), [
            'status_fulfillment' => 'processing',
            'status_payment' => 'unpaid',
        ])
        ->assertRedirect(route('admin.badges.index'))
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Saved', 'body' => null]);

    $badge->refresh();

    expect($badge->status_fulfillment->getValue())->toBe('processing')
        // ToProcessing allocates the custom_id. A raw write skipped it entirely.
        ->and($badge->custom_id)->toBe('0142-1')
        ->and($badge->activities()->where('description', 'Badge sent for printing')->exists())->toBeTrue();
});

test('the payment transition stamps paid_at and logs, and runs before fulfillment', function () {
    $badge = ($this->badge)(['status_fulfillment' => 'processing', 'paid_at' => null, 'custom_id' => '0142-1']);

    actingAs($this->admin)
        ->put(route('admin.badges.update', $badge), [
            'status_fulfillment' => 'ready_for_pickup',
            'status_payment' => 'paid',
        ])
        ->assertRedirect(route('admin.badges.index'));

    $badge->refresh();

    expect($badge->status_payment->getValue())->toBe('paid')
        ->and($badge->status_fulfillment->getValue())->toBe('ready_for_pickup')
        ->and($badge->paid_at)->not->toBeNull();
});

test('a badge cannot be moved to a state config() does not allow from its current one', function () {
    $badge = ($this->badge)();

    // pending -> picked_up is not a declared transition, and neither is pending ->
    // printed, which no transition reaches at all.
    foreach (['picked_up', 'ready_for_pickup', 'printed', 'nonsense'] as $target) {
        actingAs($this->admin)
            ->put(route('admin.badges.update', $badge), [
                'status_fulfillment' => $target,
                'status_payment' => 'unpaid',
            ])
            ->assertSessionHasErrors('status_fulfillment');
    }

    expect($badge->fresh()->status_fulfillment->getValue())->toBe('pending');
});

test('both statuses are required', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)->put(route('admin.badges.update', $badge), [])
        ->assertSessionHasErrors(['status_fulfillment', 'status_payment']);
});

test('no write path can put a euro string into a cents column', function () {
    // audit 3: the Total field rendered number_format($state / 100, 2) and had no inverse
    // on write, so an unchanged save stored "3.00" in an unsignedBigInteger. `Badge` is
    // $guarded = [], so the request is what has to refuse it.
    $badge = ($this->badge)(['total' => 300, 'subtotal' => 252, 'tax' => 48]);

    actingAs($this->admin)
        ->put(route('admin.badges.update', $badge), [
            'status_fulfillment' => 'pending',
            'status_payment' => 'unpaid',
            'total' => '3.00',
            'subtotal' => '2.52',
            'tax' => '0.48',
            'is_free_badge' => true,
            'custom_id' => 'HACKED',
            'fursuit_id' => 999,
        ])
        ->assertRedirect(route('admin.badges.index'));

    $badge->refresh();

    expect($badge->total)->toBe(300)
        ->and($badge->subtotal)->toBe(252)
        ->and($badge->tax)->toBe(48)
        ->and($badge->is_free_badge)->toBeFalse()
        ->and($badge->custom_id)->toBeNull();
});

test('a badge is soft deleted from the edit page with the old panel default copy', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)
        ->delete(route('admin.badges.destroy', $badge))
        ->assertRedirect(route('admin.badges.index'))
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Deleted', 'body' => null]);

    expect(Badge::whereKey($badge->id)->exists())->toBeFalse()
        ->and(Badge::withTrashed()->whereKey($badge->id)->exists())->toBeTrue();
});

test('the list offers no page actions', function () {
    ($this->badge)();

    ($this->scoped)(null)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page->count('pageActions', 0));

    actingAs($this->reviewer)->get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page->count('pageActions', 0));
});

test('the module admits admins and reviewers to read, and shuts everyone else out', function () {
    // BadgePolicy: viewAny/view = is_admin || is_reviewer.
    $badge = ($this->badge)();

    // Guests first: `auth` pushes them into the Identity SSO flow, and the panel owns no
    // login screen. Asserted before anyone signs in, because actingAs sticks.
    get(route('admin.badges.index'))->assertRedirect();

    actingAs($this->admin)->get(route('admin.badges.index'))->assertSuccessful();
    actingAs($this->reviewer)->get(route('admin.badges.index'))->assertSuccessful();

    actingAs($this->nobody)->get(route('admin.badges.index'))->assertForbidden();
    actingAs($this->nobody)->get(route('admin.badges.edit', $badge))->assertForbidden();
});

test('an admin may edit any badge regardless of which panel serves the route', function () {
    // BadgePolicy::update used to require a route check against the old panel's names,
    // so moving the panel would silently flip every admin to owner-rules-only and every
    // /admin/badges/{badge}/edit would 403. Answered on the actor now.
    $badge = ($this->badge)();

    expect(Gate::forUser($this->admin)->allows('update', $badge))->toBeTrue();

    actingAs($this->admin)->get(route('admin.badges.edit', $badge))->assertSuccessful();

    actingAs($this->admin)
        ->put(route('admin.badges.update', $badge), [
            'status_fulfillment' => 'processing',
            'status_payment' => 'unpaid',
        ])
        ->assertRedirect(route('admin.badges.index'));

    // And someone with neither flag still gets the owner rules, which is the whole of the
    // public ordering side.
    expect(Gate::forUser($this->nobody)->allows('update', $badge))->toBeFalse();
});

test('the policy is the one the panel expects', function () {
    expect(Gate::getPolicyFor(Badge::class))->toBeInstanceOf(BadgePolicy::class);
});

test('a reviewer may read but not write, and a stranger not even that', function () {
    $badge = ($this->badge)();

    actingAs($this->nobody)
        ->put(route('admin.badges.update', $badge), ['status_fulfillment' => 'processing', 'status_payment' => 'unpaid'])
        ->assertForbidden();

    actingAs($this->nobody)->delete(route('admin.badges.destroy', $badge))->assertForbidden();

    // BadgePolicy::delete is is_admin or owner-with-conditions, so a reviewer is not a
    // deleter even though update is open to every access-manage holder.
    actingAs($this->reviewer)->delete(route('admin.badges.destroy', $badge))->assertForbidden();

    expect(Badge::whereKey($badge->id)->exists())->toBeTrue();
});

test('the rail links to the module and carries the badge count for the selected event', function () {
    ($this->badge)();
    ($this->badge)([], [], '0143', $this->otherEvent);

    $item = collect(
        ($this->scoped)($this->event->id)->get(route('admin.dashboard'))->viewData('page')['props']['manageNav']
    )
        ->flatMap(fn ($group) => $group['items'])
        ->firstWhere('label', 'Badges');

    expect($item)->not->toBeNull()
        ->and($item['url'])->toBe(route('admin.badges.index'))
        // The chip counts badges in the selected event, through the fursuit, as today.
        ->and($item['badge']['label'] ?? null)->toBe('1');
});

test('a literal segment is never read as a record id', function () {
    // {badge} only matches digits, so a stray word never reaches the binder.
    actingAs($this->admin)->get('/admin/badges/bulk/edit')->assertNotFound();
});

test('the fulfillment states this app can reach are exactly the ones the machine declares', function () {
    // Guard for the option lists above: if a transition is added or removed, the picker
    // changes with it rather than drifting into a hardcoded list.
    $badge = ($this->badge)();

    expect((new Processing($badge))->transitionableStates())->toBe(['ready_for_pickup', 'picked_up'])
        ->and((new PickedUp($badge))->transitionableStates())->toBe(['ready_for_pickup'])
        ->and((new ReadyForPickup($badge))->transitionableStates())->toBe(['picked_up']);
});

test('saving a badge returns to the filtered list rather than the bare one', function () {
    $badge = ($this->badge)();

    // The operator narrows the list, opens a badge from it, and saves.
    $list = route('admin.badges.index', [
        'filter' => ['status_payment' => ['paid']],
        'sort' => 'sort_attendee_id',
        'dir' => 'desc',
        'page' => 2,
    ]);

    ($this->scoped)(null)->get($list)->assertSuccessful();

    $target = ($this->scoped)(null)
        ->put(route('admin.badges.update', $badge), [
            'status_payment' => $badge->status_payment->getValue(),
            'status_fulfillment' => $badge->status_fulfillment->getValue(),
        ])
        ->headers->get('Location');

    parse_str(parse_url($target, PHP_URL_QUERY) ?: '', $query);

    // Filters, sort and page all live in the query string, so a redirect to the bare index
    // route is what used to drop every one of them. Compared as parameters rather than as
    // a string: fullUrl() normalises the query into alphabetical order.
    expect(parse_url($target, PHP_URL_PATH))->toBe('/admin/badges')
        ->and($query)->toBe([
            'dir' => 'desc',
            'filter' => ['status_payment' => ['paid']],
            'page' => '2',
            'sort' => 'sort_attendee_id',
        ]);
});

test('a save with no list visited first falls back to the bare index', function () {
    $badge = ($this->badge)();

    ($this->scoped)(null)
        ->put(route('admin.badges.update', $badge), [
            'status_payment' => $badge->status_payment->getValue(),
            'status_fulfillment' => $badge->status_fulfillment->getValue(),
        ])
        ->assertRedirect(route('admin.badges.index'));
});

test('the bulk fulfillment write sets the status past the state machine', function () {
    // ReadyForPickup -> Processing is not a legal transition in either direction the graph
    // runs, which is exactly the correction this endpoint exists to make.
    $badge = ($this->badge)(['status_fulfillment' => 'ready_for_pickup']);

    expect($badge->status_fulfillment->canTransitionTo(Processing::class))->toBeFalse();

    actingAs($this->admin)
        ->post(route('admin.badges.bulk.status'), [
            'ids' => [$badge->id],
            'status_fulfillment' => 'processing',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($badge->fresh()->status_fulfillment)->toBeInstanceOf(Processing::class);
});

test('the bulk fulfillment write leaves the transition side effects alone', function () {
    $badge = ($this->badge)(['status_fulfillment' => 'pending', 'custom_id' => null]);

    actingAs($this->admin)->post(route('admin.badges.bulk.status'), [
        'ids' => [$badge->id],
        'status_fulfillment' => 'picked_up',
    ])->assertRedirect();

    $badge->refresh();

    // The status moves and nothing else does: no id allocation, no stamping. That is the
    // documented cost of skipping the state machine, and it is what makes this a repair
    // tool rather than a second way to hand a badge over.
    expect($badge->status_fulfillment->getValue())->toBe('picked_up')
        ->and($badge->custom_id)->toBeNull()
        ->and($badge->picked_up_at)->toBeNull()
        // The write still goes through the model, so the change is in the activity log.
        ->and($badge->activities()->exists())->toBeTrue();
});

test('the bulk fulfillment write counts the badges already in the target status', function () {
    $already = ($this->badge)(['status_fulfillment' => 'picked_up']);
    $moved = ($this->badge)(['status_fulfillment' => 'pending']);

    actingAs($this->admin)->post(route('admin.badges.bulk.status'), [
        'ids' => [$already->id, $moved->id],
        'status_fulfillment' => 'picked_up',
    ])->assertSessionHas('inertia.flash_data.toast.body', '1 badge set to Picked Up. 1 already were.');
});

test('the bulk fulfillment write refuses an unknown status and a non-admin', function () {
    $badge = ($this->badge)(['status_fulfillment' => 'pending']);

    actingAs($this->admin)
        ->post(route('admin.badges.bulk.status'), [
            'ids' => [$badge->id],
            'status_fulfillment' => 'shipped',
        ])
        ->assertSessionHasErrors('status_fulfillment');

    // A reviewer reads badges but does not move them. Same gate as the single-badge PUT.
    actingAs($this->reviewer)
        ->post(route('admin.badges.bulk.status'), [
            'ids' => [$badge->id],
            'status_fulfillment' => 'picked_up',
        ])
        ->assertForbidden();

    expect($badge->fresh()->status_fulfillment->getValue())->toBe('pending');
});

test('the badge list offers the bulk fulfillment write to admins only', function () {
    ($this->badge)();

    $version = app(HandleInertiaRequests::class)->version(request());

    $keys = fn (User $actor) => collect(
        actingAs($actor)->withSession([
            EventScope::SESSION_ID => null,
            EventScope::SESSION_CHOSEN => true,
        ])
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) $version,
                'X-Inertia-Partial-Component' => 'Manage/Badges/Index',
                'X-Inertia-Partial-Data' => 'bulkActions',
            ])
            ->get(route('admin.badges.index'))
            ->assertSuccessful()
            ->json('props.bulkActions')
    )->pluck('name')->all();

    expect($keys($this->admin))->toContain('setFulfillmentStatus')
        ->and($keys($this->reviewer))->not->toContain('setFulfillmentStatus');
});
