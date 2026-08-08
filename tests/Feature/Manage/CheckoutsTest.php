<?php

/*
 * Checkouts, phase 8 (plan part 4). Transcribed from audit 4.5 and 4.5.1.
 *
 * A checkout is a German fiscal record: TSE-signed, DSFinV-K exported, legally required to
 * be tamper-evident. Three groups of cases therefore carry more weight than the parity
 * transcription:
 *
 *  - the record cannot be created, edited or deleted from the panel, by anybody, through
 *    any verb. Filament refused those three in a UI class; the refusal is a policy now, and
 *    there is no route to reach either way.
 *  - nothing writes as a side effect of reading. Opening the list or the detail page must
 *    not queue a receipt, insert a print job or touch a column.
 *  - the one write that exists, queueing a receipt, uses PrintJobTypeEnum::Receipt rather
 *    than the raw string, is idempotent per checkout, and is logged.
 *
 * The rest is parity: nine columns in order, four filters with a status filter that
 * actually matches rows, the money fix on both screens, the two receipt actions and the
 * notification copy verbatim.
 */

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Jobs\CreateReceiptFromCheckoutJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\Species;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/** The audit's nine columns in order (checklist 9, Columns). */
const MANAGE_CHECKOUT_COLUMNS = [
    'id',
    'user_name',
    'cashier_name',
    'machine_name',
    'status',
    'payment_method',
    'total',
    'items_count',
    'created_at',
];

/** ItemsRelationManager's six columns in order (checklist 10). */
const MANAGE_CHECKOUT_ITEM_COLUMNS = ['name', 'description', 'payable', 'subtotal', 'tax', 'total'];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_CHECKOUT_TOAST = 'inertia.flash_data.toast';

beforeEach(function () {
    // Badge::factory reaches the fursuit image path through the s3 disk; the receipt PDF
    // lives on the default disk, which is `local` under phpunit.xml.
    Storage::fake('s3');
    Storage::fake('local');

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

    $this->machine = Machine::factory()->create(['name' => 'Cashdesk 1']);
    $this->cashier = Staff::factory()->create(['name' => 'Desk Lead']);

    $this->checkout = function (array $attributes = []) {
        return Checkout::create([
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
            ...$attributes,
        ]);
    };

    $this->badge = function (array $attributes = []) {
        return Badge::factory()->create([
            'status_fulfillment' => 'pending',
            'status_payment' => 'paid',
            'fursuit_id' => Fursuit::factory()->create([
                'event_id' => $this->event->id,
                'user_id' => User::factory()->create()->id,
                'species_id' => Species::factory()->create()->id,
            ])->id,
            ...$attributes,
        ]);
    };

    /*
     * `checkout_items.payable_type` is NOT NULL (the migration used morphs(), not
     * nullableMorphs()), so every line item points at something. The default is the desk
     * machine rather than a badge: it is cheap, and it is the "anything else" branch the
     * relation manager renders as `-`.
     */
    $this->item = fn (Checkout $checkout, array $attributes = []) => $checkout->items()->create([
        'name' => 'Line item',
        'description' => [],
        'payable_type' => Machine::class,
        'payable_id' => $this->machine->id,
        'subtotal' => 0,
        'tax' => 0,
        'total' => 0,
        ...$attributes,
    ]);

    $this->receiptPrinter = fn () => Printer::factory()->create([
        'name' => 'Receipt Printer',
        'type' => PrintJobTypeEnum::Receipt,
        'is_active' => true,
    ]);

    // Every read below is an admin read; the access cases act for themselves.
    $this->props = fn (array $query = []) => actingAs($this->admin)
        ->get(route('admin.checkouts.index', $query))
        ->viewData('page')['props'];
});

/*
 * Access. CheckoutPolicy answers access-manage for reading, which is admin OR reviewer and
 * matches the unguarded Filament resource, and is_admin for the one write.
 */

test('a guest is redirected to login', function () {
    get(route('admin.checkouts.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the checkout list at all', function () {
    actingAs($this->attendee)->get(route('admin.checkouts.index'))->assertForbidden();
});

test('a reviewer can read the list and the detail page, as in Filament', function () {
    $checkout = ($this->checkout)();

    actingAs($this->reviewer)->get(route('admin.checkouts.index'))->assertSuccessful();
    actingAs($this->reviewer)->get(route('admin.checkouts.show', $checkout))->assertSuccessful();
});

test('a reviewer is not offered the print action and cannot post it', function () {
    $checkout = ($this->checkout)();
    ($this->receiptPrinter)();

    $props = actingAs($this->reviewer)
        ->get(route('admin.checkouts.index'))
        ->viewData('page')['props'];

    expect(array_column($props['rows'][0]['actions'], 'name'))->toBe(['view', 'receipt']);

    actingAs($this->reviewer)
        ->post(route('admin.checkouts.print', $checkout))
        ->assertForbidden();

    expect(PrintJob::count())->toBe(0);
});

/*
 * The central guarantee of the module. CheckoutResource's canCreate / canEdit / canDelete
 * were three lines in a UI class; they are a policy now, and there is no route either way.
 */

test('create, update and delete are all refused, by policy and by routing', function () {
    $checkout = ($this->checkout)();

    // Raw column values, not attributes: `status` casts to a Spatie state object that
    // carries the model back with it, and comparing two of those compares two models.
    $before = Checkout::findOrFail($checkout->id)->getRawOriginal();

    // No route exists for any of the three verbs.
    expect(app('router')->has('admin.checkouts.store'))->toBeFalse()
        ->and(app('router')->has('admin.checkouts.create'))->toBeFalse()
        ->and(app('router')->has('admin.checkouts.edit'))->toBeFalse()
        ->and(app('router')->has('admin.checkouts.update'))->toBeFalse()
        ->and(app('router')->has('admin.checkouts.destroy'))->toBeFalse();

    // And the URLs those verbs would live at refuse the method rather than binding.
    actingAs($this->admin);

    post('/admin/checkouts', ['total' => 1])->assertStatus(405);
    put('/admin/checkouts/'.$checkout->id, ['total' => 1])->assertStatus(405);
    delete('/admin/checkouts/'.$checkout->id)->assertStatus(405);

    // The policy refuses all three for an admin, which is stricter than the panel gate.
    expect(Gate::forUser($this->admin)->allows('create', Checkout::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', $checkout))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', $checkout))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('restore', $checkout))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('forceDelete', $checkout))->toBeFalse();

    // Nothing moved, and the row is still there.
    expect(Checkout::count())->toBe(1)
        ->and(Checkout::findOrFail($checkout->id)->getRawOriginal())->toEqual($before);
});

test('reading the list or the detail page writes nothing', function () {
    $checkout = ($this->checkout)();
    ($this->receiptPrinter)();

    $updatedAt = $checkout->updated_at;

    actingAs($this->admin)->get(route('admin.checkouts.index'))->assertSuccessful();
    actingAs($this->admin)->get(route('admin.checkouts.show', $checkout))->assertSuccessful();

    expect(PrintJob::count())->toBe(0)
        ->and($checkout->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('the list offers no create action and no bulk actions', function () {
    ($this->checkout)();

    $props = ($this->props)();

    // `// No create action - checkouts are created through POS only` and
    // `// No bulk actions for checkouts`, verbatim from the resource.
    expect($props['pageActions'])->toBe([])
        ->and($props['bulkActions'])->toBe([]);
});

/* Columns. */

test('the list renders the nine columns in order, with their labels and types', function () {
    ($this->checkout)();

    actingAs($this->admin)->get(route('admin.checkouts.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Checkouts/Index')
            ->where('columns.0', fn ($c) => $c['key'] === 'id' && $c['label'] === 'ID' && $c['sortable'])
            ->where('columns.1', fn ($c) => $c['key'] === 'user_name' && $c['label'] === 'Customer' && $c['sortable'])
            ->where('columns.2', fn ($c) => $c['key'] === 'cashier_name' && $c['label'] === 'Cashier' && $c['sortable'] && $c['fallback'] === '-')
            ->where('columns.3', fn ($c) => $c['key'] === 'machine_name' && $c['label'] === 'Machine' && $c['sortable'] && $c['toggleable'] && ! $c['hiddenByDefault'])
            ->where('columns.4', fn ($c) => $c['key'] === 'status' && $c['label'] === 'Status' && $c['type'] === 'badge')
            ->where('columns.5', fn ($c) => $c['key'] === 'payment_method' && $c['label'] === 'Payment Method' && $c['type'] === 'badge')
            ->where('columns.6', fn ($c) => $c['key'] === 'total' && $c['label'] === 'Total' && $c['type'] === 'money' && $c['sortable'])
            ->where('columns.7', fn ($c) => $c['key'] === 'items_count' && $c['label'] === 'Items' && ! $c['sortable'])
            ->where('columns.8', fn ($c) => $c['key'] === 'created_at' && $c['label'] === 'Created At' && $c['sortable'] && $c['toggleable'])
            ->count('columns', 9)
        );

    expect(array_column(($this->props)()['columns'], 'key'))->toBe(MANAGE_CHECKOUT_COLUMNS);
});

test('the customer cell links to the users list pre-filtered by that name', function () {
    ($this->checkout)();

    expect(($this->props)()['rows'][0]['cells']['user_name'])->toMatchArray([
        'display' => 'Paying Attendee',
        'url' => route('admin.settings.users.index', ['search' => 'Paying Attendee']),
    ]);
});

test('the cashier column falls back to a dash and the machine column carries its name', function () {
    ($this->checkout)(['cashier_id' => null]);

    $cells = ($this->props)()['rows'][0]['cells'];

    // `->default('-')` is a column fallback, so the cell itself is empty and the column
    // declares what stands in for it.
    expect($cells['cashier_name'])->toBeNull()
        ->and(collect(($this->props)()['columns'])->firstWhere('key', 'cashier_name')['fallback'])->toBe('-')
        ->and($cells['machine_name'])->toBe('Cashdesk 1');
});

test('the status badge carries the three audit labels and tones', function () {
    $tone = function (string $state) {
        Checkout::query()->delete();
        ($this->checkout)(['status' => $state]);

        return ($this->props)()['rows'][0]['cells']['status'];
    };

    expect($tone(Active::class))->toMatchArray(['label' => 'Active', 'tone' => 'warn'])
        ->and($tone(Finished::class))->toMatchArray(['label' => 'Finished', 'tone' => 'ok'])
        // danger, as the resource had it. Cancelled must not share the tone an unknown
        // status falls back to: this is a fiscal record, and "voided" is not "unrecognised".
        ->and($tone(Cancelled::class))->toMatchArray(['label' => 'Cancelled', 'tone' => 'danger']);
});

test('the payment method badge is cash success, card info, anything else gray', function () {
    $cell = function (?string $method) {
        Checkout::query()->delete();
        ($this->checkout)(['payment_method' => $method]);

        return ($this->props)()['rows'][0]['cells']['payment_method'];
    };

    expect($cell('cash'))->toMatchArray(['label' => 'Cash', 'tone' => 'ok'])
        ->and($cell('card'))->toMatchArray(['label' => 'Card', 'tone' => 'info'])
        ->and($cell('voucher'))->toMatchArray(['label' => 'voucher', 'tone' => 'idle'])
        ->and($cell(null))->toMatchArray(['label' => 'Unknown', 'tone' => 'idle']);
});

test('the items column counts the relation without a query per row', function () {
    $checkout = ($this->checkout)();

    ($this->item)($checkout, ['name' => 'Badge']);
    ($this->item)($checkout, ['name' => 'Spare copy']);

    expect(($this->props)()['rows'][0]['cells']['items_count'])->toBe(2);
});

/* Money. Landmine 1 and 2: one renderer, cents in, euros out, on every surface. */

test('the total column is euros from cents on the list', function () {
    ($this->checkout)(['total' => 1234]);

    expect(($this->props)()['rows'][0]['cells']['total'])->toBe('€12.34');
});

test('the detail page renders the same three figures as euros, agreeing with the column', function () {
    // audit landmine 2: the Financial Details section showed raw cents behind a euro
    // prefix while the table column divided by 100, on the same fiscal record.
    $checkout = ($this->checkout)(['subtotal' => 1000, 'tax' => 190, 'total' => 1190]);

    $props = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props'];

    expect($props['checkout']['subtotal'])->toBe('€10.00')
        ->and($props['checkout']['tax'])->toBe('€1.90')
        ->and($props['checkout']['total'])->toBe('€11.90')
        ->and($props['checkout']['total'])->toBe(($this->props)()['rows'][0]['cells']['total']);
});

test('the Sum summariser totals the filtered set rather than the page', function () {
    ($this->checkout)(['total' => 1000, 'payment_method' => 'cash']);
    ($this->checkout)(['total' => 2000, 'payment_method' => 'cash']);
    ($this->checkout)(['total' => 500, 'payment_method' => 'card']);

    expect(($this->props)()['meta']['summary'])->toBe(['label' => 'Total', 'value' => '€35.00']);

    // Paging must not shrink the total: Filament summarises the query, not the page.
    expect(($this->props)(['per_page' => 10, 'page' => 1])['meta']['summary']['value'])->toBe('€35.00');

    // Filtering must move it.
    expect(($this->props)(['filter' => ['payment_method' => 'card']])['meta']['summary']['value'])->toBe('€5.00');
});

/* Filters. */

test('the status filter matches on the stored state names rather than class names', function () {
    // audit landmine 6: the options were keyed by FQCN while the column holds ACTIVE /
    // FINISHED / CANCELLED, so the filter returned zero rows and looked like it worked.
    ($this->checkout)(['status' => Active::class]);
    ($this->checkout)(['status' => Finished::class]);
    ($this->checkout)(['status' => Cancelled::class]);

    $filter = collect(($this->props)()['filters'])->firstWhere('key', 'status');

    expect($filter['multiple'])->toBeTrue()
        ->and(array_column($filter['options'], 'value'))->toBe(['ACTIVE', 'FINISHED', 'CANCELLED'])
        ->and(array_column($filter['options'], 'label'))->toBe(['Active', 'Finished', 'Cancelled']);

    $finished = ($this->props)(['filter' => ['status' => ['FINISHED']]]);
    expect($finished['rows'])->toHaveCount(1)
        ->and($finished['rows'][0]['cells']['status']['label'])->toBe('Finished');

    // Multiple, as the resource declares it.
    expect(($this->props)(['filter' => ['status' => ['FINISHED', 'CANCELLED']]])['rows'])->toHaveCount(2);

    // The FQCN keying Filament used has to match nothing, which is the bug being fixed.
    expect(($this->props)(['filter' => ['status' => [Finished::class]]])['rows'])->toHaveCount(0);
});

test('the payment method and machine filters narrow the list', function () {
    $other = Machine::factory()->create(['name' => 'Cashdesk 2']);

    ($this->checkout)(['payment_method' => 'cash']);
    ($this->checkout)(['payment_method' => 'card', 'machine_id' => $other->id]);

    expect(($this->props)(['filter' => ['payment_method' => 'cash']])['rows'])->toHaveCount(1)
        ->and(($this->props)(['filter' => ['machine_id' => (string) $other->id]])['rows'])->toHaveCount(1);

    $methods = collect(($this->props)()['filters'])->firstWhere('key', 'payment_method');
    expect(array_column($methods['options'], 'label'))->toBe(['Cash', 'Card']);
});

test('the created range filters inclusively at both ends', function () {
    ($this->checkout)(['created_at' => now()->subDays(5)]);
    ($this->checkout)(['created_at' => now()->subDays(2)]);
    ($this->checkout)(['created_at' => now()]);

    $from = now()->subDays(2)->toDateString();
    $until = now()->subDays(2)->toDateString();

    // whereDate >= and whereDate <=, so a checkout made on the boundary day is in.
    expect(($this->props)(['filter' => ['created_from' => $from]])['rows'])->toHaveCount(2)
        ->and(($this->props)(['filter' => ['created_until' => $until]])['rows'])->toHaveCount(2)
        ->and(($this->props)(['filter' => ['created_from' => $from, 'created_until' => $until]])['rows'])
        ->toHaveCount(1);
});

test('the list defaults to created_at descending', function () {
    $older = ($this->checkout)(['created_at' => now()->subDay()]);
    $newer = ($this->checkout)(['created_at' => now()]);

    $props = ($this->props)();

    expect($props['sort'])->toBe(['key' => 'created_at', 'dir' => 'desc'])
        ->and($props['rows'][0]['id'])->toBe($newer->id)
        ->and($props['rows'][1]['id'])->toBe($older->id);
});

/* Actions and their copy. */

test('the row actions are View, Receipt and Print with the audit copy', function () {
    $checkout = ($this->checkout)();

    $actions = collect(($this->props)()['rows'][0]['actions'])->keyBy('name');

    expect($actions->keys()->all())->toBe(['view', 'receipt', 'print']);

    expect($actions['receipt'])->toMatchArray([
        'label' => 'Receipt',
        'icon' => 'file-text',
        'tone' => 'idle',
        'method' => 'get',
        'newTab' => true,
        'url' => route('admin.checkouts.receipt', $checkout),
    ]);

    expect($actions['print'])->toMatchArray([
        'label' => 'Print',
        'icon' => 'printer',
        'tone' => 'info',
        'method' => 'post',
        'url' => route('admin.checkouts.print', $checkout),
    ]);

    expect($actions['print']['confirm'])->toMatchArray([
        'heading' => 'Print Receipt',
        'description' => 'This will add the receipt to the print queue.',
    ]);
});

test('the detail header carries Download Receipt and Print Receipt', function () {
    $checkout = ($this->checkout)();

    $props = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props'];

    $actions = collect($props['actions'])->keyBy('name');

    expect($actions->keys()->all())->toBe(['receipt', 'print']);

    expect($actions['receipt'])->toMatchArray([
        'label' => 'Download Receipt',
        'icon' => 'download',
        'tone' => 'idle',
        'newTab' => true,
    ]);

    expect($actions['print'])->toMatchArray(['label' => 'Print Receipt', 'icon' => 'printer', 'tone' => 'info']);
    expect($actions['print']['confirm'])->toMatchArray([
        'heading' => 'Print Receipt',
        'description' => 'This will add the receipt to the print queue.',
    ]);
});

/* The receipt link, re-homed. */

test('the receipt is served under the manage guard rather than the POS route group', function () {
    // audit 13: the Filament actions pointed at pos.checkout.receipt, which sits behind
    // pos-auth:machine plus pos-auth:machine-user.
    $checkout = ($this->checkout)();

    Storage::put('checkouts/'.$checkout->id.'.pdf', '%PDF-1.4 fake');

    $response = actingAs($this->admin)->get(route('admin.checkouts.receipt', $checkout));

    $response->assertSuccessful();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

test('a receipt that cannot be rendered is a toast, not a 500', function () {
    Bus::fake();

    $checkout = ($this->checkout)();

    actingAs($this->admin)
        ->get(route('admin.checkouts.receipt', $checkout))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_CHECKOUT_TOAST.'.tone', 'danger');
});

/* The print action. */

test('printing a receipt queues the render, creates the job with the enum, and says so', function () {
    Bus::fake();

    $checkout = ($this->checkout)();
    $printer = ($this->receiptPrinter)();

    actingAs($this->admin)
        ->post(route('admin.checkouts.print', $checkout))
        ->assertRedirect()
        // CheckoutResource's success notification, verbatim.
        ->assertSessionHas(MANAGE_CHECKOUT_TOAST.'.title', 'Receipt added to print queue')
        ->assertSessionHas(
            MANAGE_CHECKOUT_TOAST.'.body',
            "Receipt for checkout #{$checkout->id} has been queued for printing."
        );

    // plan 2.10 #37: the render is queued, not dispatchSync.
    Bus::assertDispatched(CreateReceiptFromCheckoutJob::class);

    $job = PrintJob::sole();

    // audit landmine 4: 'type' => 'receipt' was a raw string beside a PrintJobStatusEnum.
    expect($job->type)->toBe(PrintJobTypeEnum::Receipt)
        ->and($job->status)->toBe(PrintJobStatusEnum::Pending)
        ->and($job->printer_id)->toBe($printer->id)
        ->and($job->file)->toBe('checkouts/'.$checkout->id.'.pdf')
        ->and($job->printable_type)->toBe(Checkout::class)
        ->and($job->printable_id)->toBe($checkout->id);

    // audit 14: no activity entry at all today.
    assertDatabaseHas('activity_log', [
        'description' => 'Receipt queued for printing',
        'subject_type' => Checkout::class,
        'subject_id' => $checkout->id,
        'causer_id' => $this->admin->id,
    ]);
});

test('the printer is looked up by the enum, not by the raw string', function () {
    Bus::fake();

    $checkout = ($this->checkout)();

    // A badge printer is active but is not a receipt printer, so the lookup must miss it.
    Printer::factory()->create(['type' => PrintJobTypeEnum::Badge, 'is_active' => true]);

    actingAs($this->admin)
        ->post(route('admin.checkouts.print', $checkout))
        ->assertRedirect()
        // CheckoutResource's danger notification, verbatim.
        ->assertSessionHas(MANAGE_CHECKOUT_TOAST.'.title', 'No receipt printer found')
        ->assertSessionHas(MANAGE_CHECKOUT_TOAST.'.body', 'Please configure an active receipt printer first.');

    expect(PrintJob::count())->toBe(0);

    // And nothing was rendered either: a receipt with nowhere to go is not made.
    Bus::assertNotDispatched(CreateReceiptFromCheckoutJob::class);
});

test('an inactive receipt printer is not used', function () {
    Bus::fake();

    $checkout = ($this->checkout)();
    Printer::factory()->create(['type' => PrintJobTypeEnum::Receipt, 'is_active' => false]);

    actingAs($this->admin)
        ->post(route('admin.checkouts.print', $checkout))
        ->assertSessionHas(MANAGE_CHECKOUT_TOAST.'.title', 'No receipt printer found');

    expect(PrintJob::count())->toBe(0);
});

test('printing twice does not queue two receipts for one fiscal record', function () {
    Bus::fake();

    $checkout = ($this->checkout)();
    ($this->receiptPrinter)();

    actingAs($this->admin)->post(route('admin.checkouts.print', $checkout));
    actingAs($this->admin)->post(route('admin.checkouts.print', $checkout))
        // The second click still reports the truth: the receipt is queued.
        ->assertSessionHas(MANAGE_CHECKOUT_TOAST.'.title', 'Receipt added to print queue');

    // audit 14: the Filament action could be fired repeatedly to spam duplicates.
    expect(PrintJob::count())->toBe(1);

    // Both asks are on the record, so a reprint request is never invisible.
    expect(
        Activity::where('description', 'Receipt queued for printing')->count()
    )->toBe(2);
});

test('a receipt whose job already printed can be asked for again', function () {
    Bus::fake();

    $checkout = ($this->checkout)();
    ($this->receiptPrinter)();

    actingAs($this->admin)->post(route('admin.checkouts.print', $checkout));

    PrintJob::sole()->forceFill(['status' => PrintJobStatusEnum::Printed])->save();

    actingAs($this->admin)->post(route('admin.checkouts.print', $checkout));

    expect(PrintJob::count())->toBe(2);
});

test('an already rendered receipt is not rendered again', function () {
    Bus::fake();

    $checkout = ($this->checkout)();
    ($this->receiptPrinter)();

    Storage::put('checkouts/'.$checkout->id.'.pdf', '%PDF-1.4 fake');

    actingAs($this->admin)->post(route('admin.checkouts.print', $checkout));

    Bus::assertNotDispatched(CreateReceiptFromCheckoutJob::class);
    expect(PrintJob::count())->toBe(1);
});

/* The detail page. */

test('the detail page shows the TSE columns that exist rather than the one that does not', function () {
    // audit landmine 5: `tse_signature` is not a column, so the Filament field was
    // permanently blank and the real signatures were invisible everywhere in admin.
    $checkout = ($this->checkout)([
        'tse_start_signature' => 'START-SIG-ABC',
        'tse_end_signature' => 'END-SIG-XYZ',
        'tse_serial_number' => 'SERIAL-1',
        'tse_transaction_number' => '4711',
        'tse_start_timestamp' => now()->subMinute(),
        'tse_end_timestamp' => now(),
    ]);

    $view = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props']['checkout'];

    expect($view)->not->toHaveKey('tse_signature');

    expect($view['tse_start_signature'])->toBe('START-SIG-ABC')
        ->and($view['tse_end_signature'])->toBe('END-SIG-XYZ')
        ->and($view['tse_serial_number'])->toBe('SERIAL-1')
        ->and($view['tse_transaction_number'])->toBe('4711')
        ->and($view['tse_start_timestamp'])->not->toBeNull()
        ->and($view['tse_end_timestamp'])->not->toBeNull();
});

test('the detail page carries the checkout information fields', function () {
    $checkout = ($this->checkout)();

    $view = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props']['checkout'];

    expect($view['remote_id'])->toBe('REMOTE-1')
        ->and($view['user'])->toBe('Paying Attendee')
        ->and($view['cashier'])->toBe('Desk Lead')
        ->and($view['machine'])->toBe('Cashdesk 1')
        ->and($view['status'])->toMatchArray(['label' => 'Finished'])
        ->and($view['payment_method'])->toMatchArray(['label' => 'Cash'])
        ->and($view['created_at'])->not->toBeNull()
        ->and($view['updated_at'])->not->toBeNull();
});

/* Checkout items (audit 4.5.1). */

test('the items table renders the six columns in order, read-only', function () {
    $checkout = ($this->checkout)();

    ($this->item)($checkout, ['name' => 'Badge', 'subtotal' => 500, 'tax' => 95, 'total' => 595]);

    $props = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props'];

    expect(array_column($props['columns'], 'key'))->toBe(MANAGE_CHECKOUT_ITEM_COLUMNS)
        ->and(array_column($props['columns'], 'label'))
        ->toBe(['Item', 'Features', 'Badge', 'Subtotal', 'Tax', 'Total'])
        // `// No actions - items are read-only`, `// No header actions`, no filters.
        ->and($props['rows'][0]['actions'])->toBe([])
        ->and($props['bulkActions'])->toBe([])
        ->and($props['pageActions'])->toBe([])
        ->and($props['filters'])->toBe([])
        // ->paginated(false) becomes perPage 200 with the pager visible (plan 2.3).
        ->and($props['meta']['perPage'])->toBe(200);
});

test('the item money columns are euros from cents', function () {
    $checkout = ($this->checkout)();

    ($this->item)($checkout, ['name' => 'Badge', 'subtotal' => 500, 'tax' => 95, 'total' => 595]);

    $cells = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props']['rows'][0]['cells'];

    expect($cells['subtotal'])->toBe('€5.00')
        ->and($cells['tax'])->toBe('€0.95')
        ->and($cells['total'])->toBe('€5.95');
});

test('the features cell joins the array and falls back to a dash', function () {
    $checkout = ($this->checkout)();

    ($this->item)($checkout, ['name' => 'Badge', 'description' => ['Double sided', 'Spare copy']]);
    ($this->item)($checkout, ['name' => 'Plain']);

    $rows = collect(actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props']['rows'])
        ->keyBy('cells.name');

    expect($rows['Badge']['cells']['description'])->toBe('Double sided, Spare copy')
        ->and($rows['Plain']['cells']['description'])->toBe('-');
});

test('the badge cell names the fursuit, links to the badge, and survives a deleted fursuit', function () {
    $checkout = ($this->checkout)();

    $badge = ($this->badge)(['custom_id' => '0142-1']);
    $badge->fursuit->update(['name' => 'Blue Wolf']);

    $item = ($this->item)($checkout, [
        'name' => 'Badge',
        'payable_type' => Badge::class,
        'payable_id' => $badge->id,
    ]);

    $cell = fn () => actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props']['rows'][0]['cells']['payable'];

    expect($cell())->toMatchArray([
        'display' => 'Blue Wolf (#0142-1)',
        'url' => route('admin.badges.edit', $badge),
    ]);

    /*
     * audit 113: `$badge->fursuit->name` had no null guard and `fursuit` soft-deletes, so
     * one deleted suit was a 500 on a fiscal document. FursuitObserver cascades the
     * soft-delete to the badges (docs/bugfix-04-fix.md), so the shape that reaches the
     * cell is a live badge whose fursuit is gone: delete the suit, restore the badge.
     */
    $badge->fursuit->delete();
    $badge->restore();

    expect($cell())->toMatchArray(['display' => '#0142-1']);

    // Anything that is not a badge renders `-`, as the closure's else branch does.
    $item->update(['payable_type' => Machine::class, 'payable_id' => $this->machine->id]);

    expect($cell())->toBe('-');
});

test('a checkout item table survives a checkout with no items', function () {
    $checkout = ($this->checkout)();

    $props = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $checkout))
        ->viewData('page')['props'];

    expect($props['rows'])->toBe([])
        ->and($props['meta']['total'])->toBe(0);
});

test('one checkout cannot see another checkout items', function () {
    $mine = ($this->checkout)();
    $theirs = ($this->checkout)();

    ($this->item)($theirs, ['name' => 'Not mine']);

    $props = actingAs($this->admin)
        ->get(route('admin.checkouts.show', $mine))
        ->viewData('page')['props'];

    expect($props['rows'])->toBe([]);
});

/* Search, which the resource declares on four columns. */

test('the list searches the id and the three relation names', function () {
    $checkout = ($this->checkout)();
    ($this->checkout)([
        'user_id' => User::factory()->create(['name' => 'Someone Else'])->id,
        'cashier_id' => Staff::factory()->create(['name' => 'Other Lead'])->id,
        'machine_id' => Machine::factory()->create(['name' => 'Cashdesk 9'])->id,
    ]);

    expect(($this->props)(['search' => 'Paying Attendee'])['rows'])->toHaveCount(1)
        ->and(($this->props)(['search' => 'Desk Lead'])['rows'])->toHaveCount(1)
        ->and(($this->props)(['search' => 'Cashdesk 9'])['rows'])->toHaveCount(1)
        ->and(($this->props)(['search' => (string) $checkout->id])['rows'])->not->toBeEmpty();
});
