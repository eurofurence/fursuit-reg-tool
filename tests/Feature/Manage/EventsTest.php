<?php

/*
 * Events, phase 2. Transcribed from audit 4.1.
 *
 * The column list below is a literal array copied out of the audit rather than a
 * description of one, so a dropped column fails a test instead of quietly disappearing.
 *
 * Three things this module has to get right beyond plain parity:
 *
 *  - `archival_notice` is in the form and in the table but not in `Event::$fillable`, so
 *    every save of it is silently dropped today. It has to persist.
 *  - `mass_printed_at` was `->required()` while its own helper text said "if applicable"
 *   . It is nullable now, and an empty value means the pre-print run is still
 *    ahead - the same reading as a future date, which is what the public copy needs.
 *  - event state is computed by `Event::state()` from the dates and is not stored, so
 *    nothing here may ship a state column, filter or field.
 */

use App\Enum\EventStateEnum;
use App\Http\Controllers\Manage\EventController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Policies\EventPolicy;
use App\Services\BadgeCalculationService;
use App\Support\Manage\Action;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/** The audit's nine columns, in order. */
const MANAGE_EVENT_COLUMNS = [
    'name',
    'badge_class',
    'starts_at',
    'ends_at',
    'mass_printed_at',
    'order_starts_at',
    'order_ends_at',
    'catch_em_all_enabled',
    'archival_notice',
];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_EVENT_TOAST_TITLE = 'inertia.flash_data.toast.title';

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    $this->event = fn (array $attributes = []) => Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
        'order_starts_at' => now()->subDay(),
        'order_ends_at' => now()->addDays(10),
        'mass_printed_at' => now()->addDays(20),
        ...$attributes,
    ]);

    $this->props = fn (array $query = []) => get(route('admin.settings.events.index', $query))
        ->viewData('page')['props'];

    /** The complete form payload, so a test can vary one field and leave the rest valid. */
    $this->payload = fn (array $overrides = []) => [
        'name' => 'Eurofurence 30',
        'badge_class' => 'EF30_Badge',
        'starts_at' => '2026-09-09',
        'ends_at' => '2026-09-13',
        'order_starts_at' => '2026-04-01T12:00',
        'order_ends_at' => '2026-08-01T23:59',
        'free_badge_deadline' => '2026-07-01T23:59',
        'badge_price' => '7.50',
        'mass_printed_at' => '2026-08-15T09:30',
        'catch_em_all_enabled' => true,
        'catch_em_all_start' => '2026-09-09T10:00',
        'catch_em_all_end' => '2026-09-13T18:00',
        'archival_notice' => 'This event is archived.',
        ...$overrides,
    ];
});

/*
 * The list.
 */

test('the list renders the audit nine columns, in order, with their labels', function () {
    ($this->event)();

    actingAs($this->admin)
        ->get(route('admin.settings.events.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Events/Index')
            ->where('columns', fn ($columns) => collect($columns)->pluck('key')->all() === MANAGE_EVENT_COLUMNS)
            ->where('columns', fn ($columns) => collect($columns)->pluck('label')->all() === [
                'Name',
                'Badge Class',
                'Starts at',
                'Ends at',
                'Mass printed at',
                'Order Start',
                'Order End',
                'Catch-Em-All',
                'Archival Notice',
            ])
            ->count('columns', 9)
        );
});

test('the seven sortable columns are exactly the ones the audit marks sortable', function () {
    ($this->event)();

    actingAs($this->admin);

    $sortable = collect(($this->props)()['columns'])
        ->filter(fn ($column) => $column['sortable'])
        ->pluck('key')
        ->all();

    // catch_em_all_enabled is an IconColumn and archival_notice is a limited text column;
    // neither carries ->sortable() today.
    expect($sortable)->toBe([
        'name',
        'badge_class',
        'starts_at',
        'ends_at',
        'mass_printed_at',
        'order_starts_at',
        'order_ends_at',
    ]);
});

test('the three toggleable columns open hidden, as they do today', function () {
    ($this->event)();

    actingAs($this->admin);

    $props = ($this->props)();

    expect(collect($props['columns'])->filter(fn ($column) => $column['toggleable'])->pluck('key')->all())
        ->toBe(['badge_class', 'catch_em_all_enabled', 'archival_notice'])
        ->and($props['hiddenColumns'])
        ->toBe(['badge_class', 'catch_em_all_enabled', 'archival_notice']);
});

test('the date columns keep their formats, their diffForHumans description and an ISO title', function () {
    $event = ($this->event)();

    actingAs($this->admin);

    $cells = ($this->props)()['rows'][0]['cells'];

    // ->date(), which is config('tables.date_format'), M j, Y.
    expect($cells['starts_at']['display'])->toBe($event->starts_at->format('M j, Y'))
        ->and($cells['ends_at']['display'])->toBe($event->ends_at->format('M j, Y'))
        // ->dateTime('d.m.Y H:i') on the three timestamps.
        ->and($cells['mass_printed_at']['display'])->toBe($event->mass_printed_at->format('d.m.Y H:i'))
        ->and($cells['order_starts_at']['display'])->toBe($event->order_starts_at->format('d.m.Y H:i'))
        ->and($cells['order_ends_at']['display'])->toBe($event->order_ends_at->format('d.m.Y H:i'))
        // ->description(fn ($record) => $record->x?->diffForHumans()) on all five.
        ->and($cells['starts_at']['description'])->toBe($event->starts_at->diffForHumans())
        ->and($cells['order_ends_at']['description'])->toBe($event->order_ends_at->diffForHumans())
        ->and($cells['starts_at']['title'])->toBe($event->starts_at->toIso8601String());
});

test('an empty date cell is null so the column fallback renders instead of a formatted epoch', function () {
    // `order_ends_at` is one of the two nullable date columns, and Event::state() has a
    // branch for it, so a row without one is a shape the list has to render.
    ($this->event)(['order_ends_at' => null]);

    actingAs($this->admin);

    expect(($this->props)()['rows'][0]['cells']['order_ends_at'])->toBeNull();
});

test('badge_class falls back to Not set and archival_notice to None', function () {
    ($this->event)(['badge_class' => null, 'archival_notice' => null]);

    actingAs($this->admin);

    $props = ($this->props)();
    $fallbacks = collect($props['columns'])->keyBy('key')->map(fn ($column) => $column['fallback']);

    expect($fallbacks['badge_class'])->toBe('Not set')
        ->and($fallbacks['archival_notice'])->toBe('None')
        ->and($props['rows'][0]['cells']['badge_class'])->toBeNull()
        ->and($props['rows'][0]['cells']['archival_notice'])->toBeNull();
});

test('archival_notice is truncated at 50 characters and carries the full value as its tooltip', function () {
    $notice = Str::repeat('a', 80);

    ($this->event)(['archival_notice' => $notice]);

    actingAs($this->admin);

    $cell = ($this->props)()['rows'][0]['cells']['archival_notice'];

    // ->limit(50) plus ->tooltip(fn ($record) => $record->archival_notice).
    expect($cell['display'])->toBe(Str::limit($notice, 50))
        ->and($cell['display'])->not->toBe($notice)
        ->and($cell['title'])->toBe($notice);
});

test('catch_em_all_enabled is a boolean cell, not a formatted string', function () {
    ($this->event)(['catch_em_all_enabled' => true]);
    ($this->event)(['name' => 'Eurofurence 28', 'catch_em_all_enabled' => false, 'starts_at' => now()->subYear()]);

    actingAs($this->admin);

    $props = ($this->props)();

    expect(collect($props['columns'])->firstWhere('key', 'catch_em_all_enabled')['type'])->toBe('bool')
        ->and(collect($props['rows'])->pluck('cells.catch_em_all_enabled')->all())->toBe([true, false]);
});

test('the list carries no filters and defaults to starts_at descending', function () {
    $older = ($this->event)(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);
    $newer = ($this->event)();

    actingAs($this->admin)
        ->get(route('admin.settings.events.index'))
        ->assertInertia(fn (Assert $page) => $page
            // the old event list declares ->filters([ // ]).
            ->where('filters', [])
            ->where('sort.key', 'starts_at')
            ->where('sort.dir', 'desc')
            ->where('rows.0.id', $newer->id)
            ->where('rows.1.id', $older->id)
        );
});

test('nothing in the envelope exposes a stored event state', function () {
    // Landmine 105: state is an appended Attribute computed from the dates. A column or a
    // filter here would have to invent one, and the row transformer must not leak the
    // appended attribute either.
    ($this->event)();

    actingAs($this->admin);

    $props = ($this->props)();

    expect(collect($props['columns'])->pluck('key'))->not->toContain('state')
        ->and($props['filters'])->toBe([])
        ->and($props['rows'][0]['cells'])->not->toHaveKey('state');
});

test('sorting and paging survive the partial reload the client actually sends', function () {
    // useTableQuery visits with only=[rows,meta,filters,sort,search], and Inertia resolves
    // those by top-level key. A nested envelope answers all five with null and the client
    // merges the nulls over what it already has, so every sort and page click changes the
    // URL and nothing else.
    $first = ($this->event)(['name' => 'Aardvark Con']);
    $last = ($this->event)(['name' => 'Zebra Con']);

    // The asset version has to match or Inertia answers 409 before the controller runs.
    $version = app(HandleInertiaRequests::class)->version(request());

    $partial = fn (array $query) => actingAs($this->admin)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Manage/Events/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
        ])
        ->get(route('admin.settings.events.index', $query));

    // A partial visit answers with JSON rather than the page view, so these read the props
    // off the response directly: assertInertia only ever sees a full page load.
    $ascending = $partial(['sort' => 'name', 'dir' => 'asc'])->assertSuccessful();

    expect($ascending->json('props.sort'))->toBe(['key' => 'name', 'dir' => 'asc'])
        ->and($ascending->json('props.rows.0.id'))->toBe($first->id)
        ->and($ascending->json('props.meta.page'))->toBe(1)
        // The five requested keys have to carry data rather than null.
        ->and($ascending->json('props.filters'))->toBe([])
        ->and($ascending->json('props.search'))->toBe('');

    expect($partial(['sort' => 'name', 'dir' => 'desc'])->json('props.rows.0.id'))->toBe($last->id);

    $paged = $partial(['per_page' => 10, 'page' => 2]);

    expect($paged->json('props.rows'))->toBe([])
        ->and($paged->json('props.meta.perPage'))->toBe(10)
        ->and($paged->json('props.meta.page'))->toBe(2);
});

test('the list is not scoped to the selected event', function () {
    // Plan 2.9 lists Events among the surfaces that stay unscoped. Scoping the event list
    // by the selected event would leave an operator one row to pick from.
    $selected = ($this->event)();
    ($this->event)(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);

    actingAs($this->admin)
        ->withSession([
            EventScope::SESSION_ID => $selected->id,
            EventScope::SESSION_CHOSEN => true,
        ])
        ->get(route('admin.settings.events.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 2));
});

test('the row, bulk and page actions carry the old panel default copy', function () {
    $event = ($this->event)();

    actingAs($this->admin)
        ->get(route('admin.settings.events.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.url', route('admin.settings.events.edit', $event))
            ->where('rows.0.actions.0.name', 'edit')
            ->where('rows.0.actions.0.label', 'Edit')
            ->where('rows.0.actions.1.label', 'Delete')
            ->where('rows.0.actions.1.method', 'delete')
            // DeleteAction default copy: heading `Delete :label` with the model label.
            ->where('rows.0.actions.1.confirm.heading', 'Delete event')
            ->where('rows.0.actions.1.confirm.description', Action::DEFAULT_CONFIRM_DESCRIPTION)
            ->where('rows.0.actions.1.confirm.submit', 'Delete')
            ->where('bulkActions.0.label', 'Delete selected')
            ->where('bulkActions.0.confirm.heading', 'Delete selected events')
            ->where('bulkActions.0.confirm.description', Action::DEFAULT_CONFIRM_DESCRIPTION)
            ->where('bulkActions.0.confirm.submit', 'Delete')
            // CreateAction default copy on ManageEvents::getHeaderActions().
            ->where('pageActions.0.label', 'New event')
            ->where('pageActions.0.url', route('admin.settings.events.create'))
        );
});

/*
 * The form.
 */

test('the create form ships the three hardcoded badge classes and no record', function () {
    actingAs($this->admin)
        ->get(route('admin.settings.events.create'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Events/Form')
            ->where('event', null)
            // Landmine 110: hardcoded per convention year, kept as-is.
            ->where('badgeClassOptions', [
                ['value' => 'EF28_Badge', 'label' => 'EF28 Badge'],
                ['value' => 'EF29_Badge', 'label' => 'EF29 Badge'],
                ['value' => 'EF30_Badge', 'label' => 'EF30 Badge'],
            ])
        );
});

test('the edit form prefills each date at the granularity its control reads', function () {
    // Plan 2.6: two DatePicker fields stay date-only and five DateTimePicker fields keep
    // their time. A native date input reads Y-m-d and datetime-local reads Y-m-d\TH:i;
    // anything else renders an empty control and an operator saves a blanked field.
    $event = ($this->event)([
        'badge_class' => 'EF29_Badge',
        'catch_em_all_start' => now()->addDays(30),
        'catch_em_all_end' => now()->addDays(35),
    ]);

    $event->forceFill(['archival_notice' => 'Historical.'])->save();

    actingAs($this->admin)
        ->get(route('admin.settings.events.edit', $event))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Events/Form')
            ->where('event.id', $event->id)
            ->where('event.name', 'Eurofurence 29')
            ->where('event.badge_class', 'EF29_Badge')
            ->where('event.starts_at', $event->starts_at->format('Y-m-d'))
            ->where('event.ends_at', $event->ends_at->format('Y-m-d'))
            ->where('event.order_starts_at', $event->order_starts_at->format('Y-m-d\TH:i'))
            ->where('event.order_ends_at', $event->order_ends_at->format('Y-m-d\TH:i'))
            ->where('event.mass_printed_at', $event->mass_printed_at->format('Y-m-d\TH:i'))
            ->where('event.catch_em_all_start', $event->catch_em_all_start->format('Y-m-d\TH:i'))
            ->where('event.catch_em_all_end', $event->catch_em_all_end->format('Y-m-d\TH:i'))
            ->where('event.catch_em_all_enabled', true)
            ->where('event.archival_notice', 'Historical.')
        );
});

test('the edit form ships no state field, because there is nothing to ship', function () {
    $event = ($this->event)();

    actingAs($this->admin)
        ->get(route('admin.settings.events.edit', $event))
        ->assertInertia(fn (Assert $page) => $page->where('event', fn ($data) => ! $data->has('state')));
});

test('storing an event writes every field and flashes the stock Created toast', function () {
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)())
        ->assertRedirect(route('admin.settings.events.index'))
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Created');

    $event = Event::sole();

    expect($event->name)->toBe('Eurofurence 30')
        ->and($event->badge_class)->toBe('EF30_Badge')
        ->and($event->starts_at->format('Y-m-d'))->toBe('2026-09-09')
        ->and($event->ends_at->format('Y-m-d'))->toBe('2026-09-13')
        ->and($event->order_starts_at->format('Y-m-d H:i'))->toBe('2026-04-01 12:00')
        ->and($event->order_ends_at->format('Y-m-d H:i'))->toBe('2026-08-01 23:59')
        ->and($event->mass_printed_at->format('Y-m-d H:i'))->toBe('2026-08-15 09:30')
        ->and($event->catch_em_all_enabled)->toBeTrue()
        ->and($event->catch_em_all_start->format('Y-m-d H:i'))->toBe('2026-09-09 10:00')
        ->and($event->catch_em_all_end->format('Y-m-d H:i'))->toBe('2026-09-13 18:00');
});

test('archival_notice actually persists, which mass assignment never let it do', function () {
    // audit 107: the field is in the form and in the table but absent from Event::$fillable,
    // so every save of it is silently dropped today. The request is the allow-list here and
    // the write goes through forceFill, so the value has to survive both create and update.
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['archival_notice' => 'Archived in 2026.']))
        ->assertRedirect(route('admin.settings.events.index'));

    $event = Event::sole();

    expect($event->archival_notice)->toBe('Archived in 2026.');

    actingAs($this->admin)
        ->put(route('admin.settings.events.update', $event), ($this->payload)(['archival_notice' => 'Rewritten.']))
        ->assertRedirect(route('admin.settings.events.index'))
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Saved');

    expect($event->fresh()->archival_notice)->toBe('Rewritten.');
});

test('the badge price round-trips as euros and lands on the table as cents', function () {
    // The fee was a constant in BadgeCalculationService. It is per-event now, and the form
    // talks euros while the column stores cents, so the conversion has to survive both
    // directions or an operator sets 7,50 EUR and the desk charges 7 cents.
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['badge_price' => '7.50']))
        ->assertRedirect(route('admin.settings.events.index'));

    $event = Event::sole();

    expect($event->badge_price_cents)->toBe(750);

    actingAs($this->admin)
        ->get(route('admin.settings.events.edit', $event))
        ->assertInertia(fn (Assert $page) => $page->where('event.badge_price', '7.50'));
});

test('a price with an awkward third decimal rounds rather than truncating a cent away', function () {
    // (int) 5.10 * 100 is 509 in float arithmetic. round() is the only reason this is 510.
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['badge_price' => '5.10']))
        ->assertRedirect(route('admin.settings.events.index'));

    expect(Event::sole()->badge_price_cents)->toBe(510);
});

test('a negative price is refused, and a free-for-everyone price is allowed', function () {
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['badge_price' => '-1']))
        ->assertSessionHasErrors('badge_price');

    expect(Event::count())->toBe(0);

    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['badge_price' => '0']))
        ->assertRedirect(route('admin.settings.events.index'));

    expect(Event::sole()->badge_price_cents)->toBe(0);
});

test('the create form offers the default price rather than making the operator invent one', function () {
    actingAs($this->admin)
        ->get(route('admin.settings.events.create'))
        ->assertInertia(fn (Assert $page) => $page->where('defaultBadgePrice', '5.00'));
});

test('the charged fee follows the event, not the constant it used to be', function () {
    // The point of the column: changing it here changes what a badge costs, without a deploy.
    $event = ($this->event)(['badge_price_cents' => 1250]);

    expect(BadgeCalculationService::calculate(event: $event))->toBe(1250)
        // A spare copy is the same flat fee, and a prepaid badge is still free.
        ->and(BadgeCalculationService::calculate(isSpareCopy: true, event: $event))->toBe(1250)
        ->and(BadgeCalculationService::calculate(isFreeBadge: true, event: $event))->toBe(0);
});

test('with no event to ask, the fee falls back to the constant instead of to nothing', function () {
    // Passing no event means "the active one", and a fresh install has none. The Welcome
    // page and the FAQ still quote a price there, and it must not be 0,00 EUR.
    expect(Event::count())->toBe(0)
        ->and(BadgeCalculationService::calculate())->toBe(BadgeCalculationService::DEFAULT_FEE);
});

test('the form offers no printing cost field, because nothing read it', function () {
    // Financial tracking is gone: `cost` was write-only, and the accessors that consumed
    // it referenced a `total_revenue` attribute that never existed. A posted value must
    // not sneak back in through the request's allow-list.
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['cost' => '1914.95']))
        ->assertRedirect(route('admin.settings.events.index'));

    expect(Event::sole()->getAttribute('cost'))->toBeNull();
});

test('an unset badge class and the two optional catch dates save as null, not empty strings', function () {
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)([
            'badge_class' => '',
            'catch_em_all_start' => '',
            'catch_em_all_end' => '',
            'archival_notice' => '',
        ]))
        ->assertRedirect(route('admin.settings.events.index'));

    $event = Event::sole();

    expect($event->badge_class)->toBeNull()
        ->and($event->catch_em_all_start)->toBeNull()
        ->and($event->catch_em_all_end)->toBeNull()
        ->and($event->archival_notice)->toBeNull();
});

test('mass_printed_at saves empty as null, because no run scheduled is not a missed run', function () {
    // The column used to be NOT NULL with a `useCurrent()` default, so a new event claimed the
    // pre-print run had happened the moment it was created and the public pages told attendees
    // to collect from the second day. Empty has to reach the database as null.
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['mass_printed_at' => '']))
        ->assertRedirect(route('admin.settings.events.index'));

    expect(Event::sole()->mass_printed_at)->toBeNull();
});

test('a set mass_printed_at can be cleared again', function () {
    $event = ($this->event)(['mass_printed_at' => now()->addDays(20)]);

    actingAs($this->admin)
        ->put(route('admin.settings.events.update', $event), ($this->payload)(['mass_printed_at' => '']))
        ->assertRedirect(route('admin.settings.events.index'));

    expect($event->refresh()->mass_printed_at)->toBeNull();
});

test('the required fields are required, free_badge_deadline among them', function () {
    // free_badge_deadline decides how long the badge included with registration is
    // honoured for free, and the front page and FAQ both quote it, so an event without
    // one is not a valid event.
    $required = [
        'name', 'starts_at', 'ends_at', 'order_starts_at', 'order_ends_at',
        'free_badge_deadline', 'badge_price',
    ];

    foreach ($required as $field) {
        actingAs($this->admin)
            ->post(route('admin.settings.events.store'), ($this->payload)([$field => null]))
            ->assertSessionHasErrors($field);
    }

    expect(Event::count())->toBe(0);
});

test('badge_class must be one of the three options the form offers', function () {
    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['badge_class' => 'EF99_Badge']))
        ->assertSessionHasErrors('badge_class');

    expect(Event::count())->toBe(0);

    actingAs($this->admin)
        ->post(route('admin.settings.events.store'), ($this->payload)(['badge_class' => 'EF28_Badge']))
        ->assertRedirect(route('admin.settings.events.index'));

    expect(Event::sole()->badge_class)->toBe('EF28_Badge')
        ->and(array_keys(EventController::BADGE_CLASS_OPTIONS))
        ->toBe(['EF28_Badge', 'EF29_Badge', 'EF30_Badge']);
});

test('nothing outside the form field list can round-trip through a save', function () {
    // The write is a forceFill, so the request has to be the allow-list. `id` is the one
    // that would be catastrophic.
    $event = ($this->event)();

    actingAs($this->admin)
        ->put(route('admin.settings.events.update', $event), ($this->payload)([
            'id' => 999999,
            'state' => 'OPEN',
        ]))
        ->assertRedirect(route('admin.settings.events.index'));

    expect($event->fresh()->id)->toBe($event->id)
        ->and(Event::sole()->getAttributes())->not->toHaveKey('state');
});

test('the order window fields are the only lever over the computed state', function () {
    // Landmine 105 restated as behaviour: there is no state to write, so an update that
    // moves order_ends_at into the past is what closes an event.
    $event = ($this->event)();

    expect($event->state)->toBe(EventStateEnum::OPEN);

    actingAs($this->admin)
        ->put(route('admin.settings.events.update', $event), ($this->payload)([
            'starts_at' => now()->addDays(30)->format('Y-m-d'),
            'ends_at' => now()->addDays(35)->format('Y-m-d'),
            'order_starts_at' => now()->subDays(10)->format('Y-m-d\TH:i'),
            'order_ends_at' => now()->subDay()->format('Y-m-d\TH:i'),
        ]))
        ->assertRedirect(route('admin.settings.events.index'));

    expect($event->fresh()->state)->toBe(EventStateEnum::CLOSED);
});

/*
 * Deletes.
 */

test('an event is hard deleted, one at a time or in bulk', function () {
    // audit 7.7: Event carries no SoftDeletes and stays a hard delete.
    $first = ($this->event)();
    $second = ($this->event)(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);

    actingAs($this->admin)
        ->delete(route('admin.settings.events.destroy', $first))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Deleted');

    expect(Event::whereKey($first->id)->exists())->toBeFalse();

    actingAs($this->admin)
        ->delete(route('admin.settings.events.bulk.destroy'), ['ids' => [$second->id]])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Deleted');

    expect(Event::count())->toBe(0);
});

test('deleting an event that still owns fursuits is refused, single and bulk', function () {
    /*
     * The event delete is a hard delete, and `fursuits.event_id` and
     * `badges.fursuit_id` are both `ON DELETE CASCADE`. Without the guard, deleting a
     * past convention removes every fursuit and every badge of it physically: SoftDeletes
     * never runs, FursuitObserver never runs, no `deleted_at` is written, no activity
     * entry is logged, and the paid badges backing DSFinV-K and TSE reconciliation are
     * gone with no restore path, because EventPolicy deliberately has no `restore` or
     * `forceDelete`.
     */
    $event = ($this->event)();
    $fursuit = Fursuit::factory()->create(['event_id' => $event->id]);
    $badge = Badge::factory()->create(['fursuit_id' => $fursuit->id]);

    actingAs($this->admin)
        ->delete(route('admin.settings.events.destroy', $event))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Nothing was deleted');

    expect(Event::whereKey($event->id)->exists())->toBeTrue()
        ->and(Fursuit::withTrashed()->whereKey($fursuit->id)->exists())->toBeTrue()
        ->and(Badge::withTrashed()->whereKey($badge->id)->exists())->toBeTrue();

    actingAs($this->admin)
        ->delete(route('admin.settings.events.bulk.destroy'), ['ids' => [$event->id]])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Nothing was deleted');

    expect(Event::whereKey($event->id)->exists())->toBeTrue()
        ->and(Badge::withTrashed()->whereKey($badge->id)->exists())->toBeTrue();
});

test('a soft-deleted fursuit still blocks the delete', function () {
    // A soft delete leaves the row in place, and the database-level cascade does not read
    // `deleted_at`, so the rows would still go.
    $event = ($this->event)();
    $fursuit = Fursuit::factory()->create(['event_id' => $event->id]);
    $fursuit->delete();

    actingAs($this->admin)
        ->delete(route('admin.settings.events.destroy', $event))
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Nothing was deleted');

    expect(Event::whereKey($event->id)->exists())->toBeTrue();
});

test('a bulk selection spanning an empty and a populated event deletes neither', function () {
    // All-or-nothing, the same rule the policy loop above follows.
    $empty = ($this->event)();
    $populated = ($this->event)(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);
    Fursuit::factory()->create(['event_id' => $populated->id]);

    actingAs($this->admin)
        ->delete(route('admin.settings.events.bulk.destroy'), ['ids' => [$empty->id, $populated->id]])
        ->assertSessionHas(MANAGE_EVENT_TOAST_TITLE, 'Nothing was deleted');

    expect(Event::count())->toBe(2);
});

test('DELETE /admin/settings/events/bulk is not read as a record id', function () {
    expect(route('admin.settings.events.bulk.destroy', absolute: false))->toBe('/admin/settings/events/bulk');
});

test('bulk delete refuses an unauthorized caller even when the ids match nothing', function () {
    // The all-or-nothing loop only speaks for rows it loaded, so an empty
    // result set would otherwise walk straight past it into the success toast.
    ($this->event)();

    actingAs($this->reviewer)
        ->delete(route('admin.settings.events.bulk.destroy'), ['ids' => [999999]])
        ->assertForbidden();

    actingAs($this->admin)
        ->delete(route('admin.settings.events.bulk.destroy'), ['ids' => [999999]])
        ->assertRedirect();

    expect(Event::count())->toBe(1);
});

/*
 * Authorisation.
 */

test('every ability belongs to an admin, so a reviewer is shut out of the whole module', function () {
    // EventPolicy gates viewAny/view/create/update/delete on is_admin.
    $event = ($this->event)();

    actingAs($this->reviewer);

    get(route('admin.settings.events.index'))->assertForbidden();
    get(route('admin.settings.events.create'))->assertForbidden();
    post(route('admin.settings.events.store'), ($this->payload)())->assertForbidden();
    get(route('admin.settings.events.edit', $event))->assertForbidden();
    put(route('admin.settings.events.update', $event), ($this->payload)())->assertForbidden();
    delete(route('admin.settings.events.destroy', $event))->assertForbidden();
    delete(route('admin.settings.events.bulk.destroy'), ['ids' => [$event->id]])->assertForbidden();

    expect(Event::count())->toBe(1)
        ->and(Event::sole()->name)->toBe('Eurofurence 29');
});

test('an unauthorised write is a 403 rather than a 422 about its payload', function () {
    // The gate lives in the FormRequest, which runs before validation. Gating in the
    // controller body would answer a reviewer with a complaint about their date fields.
    actingAs($this->reviewer)
        ->post(route('admin.settings.events.store'), ['name' => ''])
        ->assertForbidden()
        ->assertSessionHasNoErrors();
});

test('the policy is registered', function () {
    expect(Gate::getPolicyFor(Event::class))->toBeInstanceOf(EventPolicy::class);
});

test('the settings submenu links to the module for an admin', function () {
    /*
     * Events is a Settings pane rather than a rail entry: it is edited a handful of times per
     * convention and every field on it configures the convention. So the visibility question
     * the rail used to answer is now answered by the submenu, from the same policy.
     *
     * The reviewer half went with Settings itself, which is admin-only now
     * (docs/admin/roles.md): a reviewer never reaches the page this submenu is attached to,
     * so ReviewerScopeTest asserts the refusal instead.
     */
    ($this->event)();

    $panes = fn (User $user) => collect(
        actingAs($user)->get(route('admin.settings.general'))
            ->viewData('page')['props']['manageSettingsNav']
    );

    expect($panes($this->admin)->pluck('key'))->toContain('events');

    // And nothing left an Events entry behind in the rail.
    $rail = collect(actingAs($this->admin)->get(route('admin.dashboard'))
        ->viewData('page')['props']['manageNav'])
        ->flatMap(fn ($group) => $group['items'])
        ->pluck('label');

    expect($rail)->not->toContain('Events');
});
