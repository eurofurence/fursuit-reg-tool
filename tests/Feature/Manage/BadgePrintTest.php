<?php

/*
 * The badge print pipeline, phase 7. Transcribed from audit 4.2, the
 * `printBadge` row action and the `printBadgeBulk` bulk action.
 *
 * This is the highest-risk slice in the migration: it drives real hardware at a live
 * convention, so the cases below weigh four things above the parity transcription.
 *
 *  - nothing prints on its own. Rendering the badge list, and the five-second poll behind
 *    it, must create no batch, no print job and no state change. Every card that comes out
 *    of a printer is a POST somebody deliberately made.
 *  - the batch is the unit. Even one badge gets its own, because the batch is what carries
 *    the frozen sequence, the pause-on-failure and the printing lock.
 *  - the transition is a transition. Printing moves the badge to Processing through the
 *    state machine, which is what allocates `custom_id`; a raw write would put a card with
 *    no id on the stack.
 *  - a refused print writes nothing. An unauthorised actor, an unknown printer and a
 *    deactivated printer all leave the badge exactly where it was.
 */

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Http\Controllers\Manage\BadgePrintController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use App\Support\Manage\EventScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_BADGE_PRINT_TOAST = 'inertia.flash_data.toast.title';

beforeEach(function () {
    // The badge list's image column reads the private s3 disk, as the old badge list does.
    Storage::fake('s3');

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->nobody = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->printer = Printer::factory()->badge()->create(['name' => 'Badge Printer 1', 'is_active' => true]);

    /*
     * A badge that can actually reach a printer.
     *
     * `custom_id` is set at creation, before withPrintFile() fingerprints the badge.
     * ToProcessing only allocates one when it is missing, so presetting it keeps the
     * artwork current through the transition and keeps these tests about the pipeline
     * rather than about mpdf. The EventUser row is what ToProcessing reads the attendee
     * id from, and without it the transition throws.
     */
    $this->badge = function (string $customId = '0142-1', array $attributes = []) {
        $owner = User::factory()->create();

        EventUser::factory()->create([
            'user_id' => $owner->id,
            'event_id' => $this->event->id,
            'attendee_id' => explode('-', $customId)[0],
        ]);

        return Badge::factory()->withPrintFile()->create([
            'custom_id' => $customId,
            'status_fulfillment' => 'pending',
            'status_payment' => 'paid',
            'extra_copy' => false,
            'extra_copy_of' => null,
            'printing_locked_at' => null,
            'fursuit_id' => Fursuit::factory()->create([
                'event_id' => $this->event->id,
                'user_id' => $owner->id,
                'species_id' => Species::firstOrCreate(['name' => 'Blue Fox'], ['type' => 'canine', 'checked' => true])->id,
                'name' => 'Nibbles',
            ])->id,
            ...$attributes,
        ]);
    };

    $this->scoped = fn () => actingAs($this->admin)->withSession([
        EventScope::SESSION_ID => null,
        EventScope::SESSION_CHOSEN => true,
    ]);
});

/*
 * Nothing prints as a side effect. The first group, because it is the one that would
 * quietly burn a stack of cards.
 */

test('rendering the badge list queues nothing', function () {
    $badge = ($this->badge)();

    ($this->scoped)()->get(route('admin.badges.index'))->assertSuccessful();

    expect(PrintBatch::count())->toBe(0)
        ->and(PrintJob::count())->toBe(0)
        ->and($badge->fresh()->status_fulfillment->getValue())->toBe('pending')
        ->and($badge->fresh()->printing_locked_at)->toBeNull();
});

test('the five-second poll queues nothing', function () {
    $badge = ($this->badge)();

    // The poll exactly as usePoll sends it: a partial visit for the data props and the
    // bulk actions, which is what carries the printer options.
    ($this->scoped)()
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Manage/Badges/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,bulkActions',
        ])
        ->get(route('admin.badges.index'))
        ->assertSuccessful();

    expect(PrintBatch::count())->toBe(0)
        ->and(PrintJob::count())->toBe(0)
        ->and($badge->fresh()->status_fulfillment->getValue())->toBe('pending');
});

test('opening the edit page queues nothing', function () {
    $badge = ($this->badge)();

    ($this->scoped)()->get(route('admin.badges.edit', $badge))->assertSuccessful();

    expect(PrintBatch::count())->toBe(0)->and(PrintJob::count())->toBe(0);
});

/*
 * The row action.
 */

test('the row action transitions the badge to Processing through the state machine', function () {
    $badge = ($this->badge)();

    expect($badge->status_fulfillment->getValue())->toBe('pending');

    actingAs($this->admin)->post(route('admin.badges.print', $badge))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $badge->refresh();

    expect($badge->status_fulfillment)->toBeInstanceOf(Processing::class)
        // ToProcessing is what stamps the activity entry; a raw column write would not.
        ->and($badge->activities()->where('description', 'Badge sent for printing')->exists())->toBeTrue();
});

test('the row action gives a single badge its own batch, through BadgePrintQueue', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)->post(route('admin.badges.print', $badge))->assertRedirect();

    $batch = PrintBatch::sole();

    expect($batch->total_jobs)->toBe(1)
        // build() produces a draft and the queue promotes it, because a draft is not
        // selectable by the print agent.
        ->and($batch->status)->toBe(PrintBatchStatusEnum::Ready)
        ->and($batch->created_by_id)->toBe($this->admin->id)
        ->and($batch->event_id)->toBe($this->event->id);

    $job = PrintJob::sole();

    expect($job->print_batch_id)->toBe($batch->id)
        ->and($job->sequence)->toBe(1)
        ->and($job->printable_id)->toBe($badge->id)
        // The lock is the other half of what a batch carries: the artwork is rendered, so
        // the attendee can no longer edit the order out from under the card.
        ->and($badge->fresh()->printing_locked_at)->not->toBeNull();
});

test('the row action falls back to the first active badge printer', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)->post(route('admin.badges.print', $badge))->assertRedirect();

    expect(PrintBatch::sole()->printer_id)->toBe($this->printer->id);
});

test('the row action still prints a badge that cannot reach Processing', function () {
    // A reprint of a card that has already been collected. canTransitionTo() is false, so
    // the transition is skipped and the badge is queued from where it stands.
    $badge = ($this->badge)('0142-1', ['status_fulfillment' => 'picked_up']);

    actingAs($this->admin)->post(route('admin.badges.print', $badge))->assertRedirect();

    expect($badge->fresh()->status_fulfillment)->toBeInstanceOf(PickedUp::class)
        ->and(PrintBatch::count())->toBe(1)
        ->and(PrintJob::count())->toBe(1);
});

test('the same print, posted twice, produces one batch and one card', function () {
    /*
     * A browser back and resubmit, two operators on the same row, or the confirm
     * double-clicked. Nothing downstream refused the second one: build() created a job
     * and stamped the lock whatever the badge already had, so one order came out of the
     * printer twice and both cards went into the pickup bin.
     */
    $badge = ($this->badge)();

    actingAs($this->admin)->post(route('admin.badges.print', $badge))->assertRedirect();
    actingAs($this->admin)->post(route('admin.badges.print', $badge))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_BADGE_PRINT_TOAST, 'Nothing was queued');

    expect(PrintBatch::count())->toBe(1)
        ->and(PrintJob::where('printable_id', $badge->id)->count())->toBe(1);
});

test('a bulk selection that includes an already queued badge queues only the rest', function () {
    $queued = ($this->badge)('0100-1');
    $fresh = ($this->badge)('0101-1');

    actingAs($this->admin)->post(route('admin.badges.print', $queued))->assertRedirect();

    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'ids' => [$queued->id, $fresh->id],
        'printer_id' => $this->printer->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $second = PrintBatch::orderByDesc('id')->first();

    expect(PrintBatch::count())->toBe(2)
        ->and($second->total_jobs)->toBe(1)
        ->and($second->printJobs()->pluck('printable_id')->all())->toBe([$fresh->id])
        ->and(PrintJob::where('printable_id', $queued->id)->count())->toBe(1);
});

test('the row action flashes a toast, which the old panel action never did', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)->post(route('admin.badges.print', $badge))
        ->assertSessionHas(MANAGE_BADGE_PRINT_TOAST, 'Badge queued for printing');
});

/*
 * The bulk action.
 */

test('the bulk action queues the selection to the chosen printer as one batch', function () {
    $first = ($this->badge)('0100-1');
    $second = ($this->badge)('0101-1');
    $other = Printer::factory()->badge()->create(['name' => 'Badge Printer 2']);

    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'ids' => [$first->id, $second->id],
        'printer_id' => $other->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $batch = PrintBatch::sole();

    expect($batch->printer_id)->toBe($other->id)
        ->and($batch->total_jobs)->toBe(2)
        ->and($batch->status)->toBe(PrintBatchStatusEnum::Ready)
        ->and($batch->printJobs()->pluck('printable_id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

test('the print order is the batch\'s own, not a second one layered on top', function () {
    // PrintBatch::sortBadgesForPrinting(): attendee ascending, badge number *descending*
    // inside an attendee, so the spare copies land under the main badge. The selection is
    // sent in the opposite order to prove the ordering is not simply the request's.
    $owner = User::factory()->create();

    EventUser::factory()->create([
        'user_id' => $owner->id,
        'event_id' => $this->event->id,
        'attendee_id' => '1001',
    ]);

    $fursuit = fn () => Fursuit::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $owner->id,
        'species_id' => Species::firstOrCreate(['name' => 'Blue Fox'], ['type' => 'canine', 'checked' => true])->id,
    ])->id;

    $spare = Badge::factory()->withPrintFile()->create(['custom_id' => '1001-2', 'status_fulfillment' => 'pending', 'fursuit_id' => $fursuit()]);
    $main = Badge::factory()->withPrintFile()->create(['custom_id' => '1001-1', 'status_fulfillment' => 'pending', 'fursuit_id' => $fursuit()]);
    $later = ($this->badge)('1002-1');

    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'ids' => [$later->id, $main->id, $spare->id],
        'printer_id' => $this->printer->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(PrintBatch::sole()->printJobs()->orderBy('sequence')->pluck('printable_id')->all())
        ->toBe([$spare->id, $main->id, $later->id]);
});

/*
 * "Select all matching": the run is the whole of what the filter matches, resolved on the
 * server. The browser holds one page of ids and the operator is asking for the thousand
 * behind them, so anything the client could send is the wrong answer.
 */

test('select all matching queues every badge the filter matches, not the ids posted', function () {
    $first = ($this->badge)('0100-1');
    $second = ($this->badge)('0101-1');
    $third = ($this->badge)('0102-1');

    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'all' => true,
        // Deliberately naming one badge: `all` means the filter, and `ids` is ignored.
        'ids' => [$first->id],
        'query' => '',
        'printer_id' => $this->printer->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(PrintBatch::sole()->printJobs()->pluck('printable_id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id, $third->id])->sort()->values()->all());
});

test('select all matching narrows by the filters the list was drawn with', function () {
    $pending = ($this->badge)('0100-1');
    ($this->badge)('0101-1', ['status_fulfillment' => 'picked_up']);

    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'all' => true,
        'query' => http_build_query(['filter' => ['status_fulfillment' => ['pending']]]),
        'printer_id' => $this->printer->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(PrintBatch::sole()->printJobs()->pluck('printable_id')->all())->toBe([$pending->id]);
});

test('select all matching stays inside the selected event', function () {
    $mine = ($this->badge)('0100-1');

    $otherEvent = Event::factory()->create(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);
    $stranger = User::factory()->create();
    EventUser::factory()->create([
        'user_id' => $stranger->id,
        'event_id' => $otherEvent->id,
        'attendee_id' => '0900',
    ]);
    Badge::factory()->withPrintFile()->create([
        'custom_id' => '0900-1',
        'status_fulfillment' => 'pending',
        'status_payment' => 'paid',
        'fursuit_id' => Fursuit::factory()->create([
            'event_id' => $otherEvent->id,
            'user_id' => $stranger->id,
            'species_id' => Species::firstOrCreate(['name' => 'Blue Fox'], ['type' => 'canine', 'checked' => true])->id,
        ])->id,
    ]);

    // The event scope is session state, not something the forwarded query can widen.
    actingAs($this->admin)
        ->withSession([EventScope::SESSION_ID => $this->event->id, EventScope::SESSION_CHOSEN => true])
        ->post(route('admin.badges.bulk.print'), [
            'all' => true,
            'query' => '',
            'printer_id' => $this->printer->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

    expect(PrintBatch::sole()->printJobs()->pluck('printable_id')->all())->toBe([$mine->id]);
});

test('select all matching needs no ids at all', function () {
    ($this->badge)('0100-1');

    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'all' => true,
        'printer_id' => $this->printer->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(PrintBatch::count())->toBe(1);
});

test('a bulk print with neither ids nor all is a validation error', function () {
    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'printer_id' => $this->printer->id,
    ])->assertSessionHasErrors('ids');

    expect(PrintBatch::count())->toBe(0);
});

test('only the print action offers to go past the page', function () {
    ($this->badge)();

    $props = ($this->scoped)()->get(route('admin.badges.index'))->viewData('page')['props'];

    // The fulfillment write bypasses the state machine, so it stays page-only.
    expect(collect($props['bulkActions'])->firstWhere('name', 'printBadgeBulk')['selectAll'])->toBeTrue()
        ->and(collect($props['bulkActions'])->firstWhere('name', 'setFulfillmentStatus')['selectAll'])->toBeFalse();
});

test('the bulk action requires a printer and queues nothing without one', function () {
    $badge = ($this->badge)();

    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'ids' => [$badge->id],
    ])->assertSessionHasErrors('printer_id');

    expect(PrintBatch::count())->toBe(0)
        ->and(PrintJob::count())->toBe(0)
        ->and($badge->fresh()->status_fulfillment->getValue())->toBe('pending');
});

test('the bulk action refuses a printer that is not an active badge printer', function () {
    $badge = ($this->badge)();
    $receipt = Printer::factory()->receipt()->create();
    $inactive = Printer::factory()->badge()->create(['is_active' => false]);

    foreach ([$receipt->id, $inactive->id, 999999] as $printerId) {
        actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
            'ids' => [$badge->id],
            'printer_id' => $printerId,
        ])->assertSessionHasErrors('printer_id');
    }

    expect(PrintBatch::count())->toBe(0)
        ->and(PrintJob::count())->toBe(0)
        ->and($badge->fresh()->status_fulfillment->getValue())->toBe('pending');
});

test('the bulk action says so when the selection queued nothing', function () {
    actingAs($this->admin)->post(route('admin.badges.bulk.print'), [
        'ids' => [999999],
        'printer_id' => $this->printer->id,
    ])->assertSessionHas(MANAGE_BADGE_PRINT_TOAST, 'Nothing was queued');

    expect(PrintBatch::count())->toBe(0)->and(PrintJob::count())->toBe(0);
});

/*
 * Access. Both endpoints ask the same question the old panel actions inherited from the
 * resource: may this actor see badges at all.
 */

test('a guest cannot print', function () {
    $badge = ($this->badge)();

    post(route('admin.badges.print', $badge))->assertRedirect();
    post(route('admin.badges.bulk.print'), ['ids' => [$badge->id], 'printer_id' => $this->printer->id])
        ->assertRedirect();

    expect(PrintBatch::count())->toBe(0)
        ->and(PrintJob::count())->toBe(0)
        ->and($badge->fresh()->status_fulfillment->getValue())->toBe('pending');
});

test('a user who cannot see badges cannot print, and nothing is queued', function () {
    $badge = ($this->badge)();

    actingAs($this->nobody)->post(route('admin.badges.print', $badge))->assertForbidden();

    actingAs($this->nobody)->post(route('admin.badges.bulk.print'), [
        'ids' => [$badge->id],
        'printer_id' => $this->printer->id,
    ])->assertForbidden();

    expect(PrintBatch::count())->toBe(0)
        ->and(PrintJob::count())->toBe(0)
        ->and($badge->fresh()->status_fulfillment->getValue())->toBe('pending')
        ->and($badge->fresh()->printing_locked_at)->toBeNull();
});

/*
 * The old panel actions were offered to reviewers as well as admins. They are admin-only
 * now: a reviewer reads badges to work the fursuit queue, and sending cards to a printer
 * is desk work. Both endpoints and both action declarations are asserted, because the
 * button and the write have to answer the same question. See docs/admin/roles.md.
 */
test('a reviewer cannot print, single or bulk, and is offered neither action', function () {
    $badge = ($this->badge)();

    actingAs($this->reviewer)->post(route('admin.badges.print', $badge))->assertForbidden();

    actingAs($this->reviewer)->post(route('admin.badges.bulk.print'), [
        'ids' => [$badge->id],
        'printer_id' => $this->printer->id,
    ])->assertForbidden();

    $props = actingAs($this->reviewer)
        ->get(route('admin.badges.index'))
        ->viewData('page')['props'];

    expect(collect($props['rows'][0]['actions'])->firstWhere('name', 'printBadge'))->toBeNull()
        ->and(collect($props['bulkActions'])->firstWhere('name', 'printBadgeBulk'))->toBeNull()
        ->and(PrintBatch::count())->toBe(0)
        ->and(PrintJob::count())->toBe(0);
});

/*
 * The declarations, verbatim from the audit.
 */

test('the list declares the print row action with its confirm copy', function () {
    ($this->badge)();

    $rows = ($this->scoped)()->get(route('admin.badges.index'))->viewData('page')['props']['rows'];

    $action = collect($rows[0]['actions'])->firstWhere('name', 'printBadge');

    expect($action)->not->toBeNull()
        ->and($action['label'])->toBe('Print Badge')
        ->and($action['icon'])->toBe('printer')
        ->and($action['tone'])->toBe('warn')
        ->and($action['method'])->toBe('post')
        ->and($action['confirm'])->toBe([
            'heading' => 'Print Badge',
            'description' => 'Are you sure you would like to do this?',
            'submit' => 'Confirm',
        ]);
});

test('the list declares the bulk print action with its modal copy and printer select', function () {
    ($this->badge)();

    $props = ($this->scoped)()->get(route('admin.badges.index'))->viewData('page')['props'];

    // Two: the print run, and the bulk fulfillment write beside it.
    expect($props['bulkActions'])->toHaveCount(2);

    $action = $props['bulkActions'][0];

    expect($action['name'])->toBe('printBadgeBulk')
        ->and($action['label'])->toBe('Print Badges')
        ->and($action['icon'])->toBe('printer')
        ->and($action['tone'])->toBe('warn')
        ->and($action['method'])->toBe('post')
        ->and($action['confirm'])->toBe([
            'heading' => 'Print Selected Badges',
            'description' => 'This will print all selected badges to the specified printer.',
            'submit' => 'Confirm',
        ])
        ->and($action['fields'])->toBe([[
            'key' => 'printer_id',
            'label' => 'Select Printer',
            'type' => 'select',
            'options' => [['value' => $this->printer->id, 'label' => 'Badge Printer 1']],
            // Pre-picked so a one-printer desk never opens the select at all.
            'default' => $this->printer->id,
            'required' => true,
            'helper' => 'Select a specific printer for all selected badges.',
        ]]);
});

test('the printer select defaults to the first active badge printer', function () {
    ($this->badge)();

    $second = Printer::factory()->badge()->create(['name' => 'Badge Printer 2', 'is_active' => true]);

    // bulkAction() is gated, so it needs an actor to return anything at all.
    actingAs($this->admin);

    $default = fn () => collect(BadgePrintController::bulkAction()->toArray()['fields'])
        ->firstWhere('key', 'printer_id')['default'];

    // Options are ordered by name, so the default is the head of the list the operator
    // sees rather than an arbitrary row.
    expect($default())->toBe($this->printer->id);

    // And it follows the hardware: switch the first one off and the default moves on
    // rather than pre-selecting a printer the request would refuse.
    $this->printer->update(['is_active' => false]);

    expect($default())->toBe($second->id);
});

test('the printer options follow the hardware rather than freezing at table build', function () {
    ($this->badge)();

    // Audit 100: the old panel evaluated the option list once when the table was built, so a
    // printer switched off mid-shift stayed on offer. The list re-reads its bulk actions
    // on the same poll as its rows.
    $this->printer->update(['is_active' => false]);
    $second = Printer::factory()->badge()->create(['name' => 'Badge Printer 2']);

    $props = ($this->scoped)()->get(route('admin.badges.index'))->viewData('page')['props'];

    expect($props['bulkActions'][0]['fields'][0]['options'])
        ->toBe([['value' => $second->id, 'label' => 'Badge Printer 2']]);
});

test('a user who cannot see badges is offered no print action at all', function () {
    // The button and the endpoint answer the same question, so an action that is not
    // offered is also one that cannot be posted (asserted above).
    expect(actingAs($this->nobody)->get(route('admin.badges.index'))->status())->toBe(403);
});

test('the bulk action moves the approval cutoff past the run it just queued', function () {
    $early = ($this->badge)('0100-1');
    $late = ($this->badge)('0200-1');

    $early->fursuit->update(['approved_at' => now()->subHours(2)]);
    $late->fursuit->update(['approved_at' => now()->subMinutes(10)]);

    // The operator is standing on a filtered list, and lands back on it.
    $from = route('admin.badges.index', ['filter' => ['status_payment' => ['paid']]]);

    $response = actingAs($this->admin)
        ->from($from)
        ->post(route('admin.badges.bulk.print'), [
            'ids' => [$early->id, $late->id],
            'printer_id' => $this->printer->id,
        ]);

    // The bound is the newest badge in the run, in the format the datetime-local control
    // round-trips, and the rest of the query string survives.
    $target = $response->headers->get('Location');

    parse_str(parse_url($target, PHP_URL_QUERY) ?: '', $query);

    // Parsed, because Fursuit does not cast `approved_at`: it comes back a raw string.
    expect($query['filter']['approved_from'])
        ->toBe(Carbon::parse($late->fursuit->fresh()->approved_at)->format('Y-m-d\TH:i'))
        ->and($query['filter']['status_payment'])->toBe(['paid']);
});

test('a run with no approval timestamps leaves the cutoff alone', function () {
    $badge = ($this->badge)();

    $badge->fursuit->update(['approved_at' => null]);

    // Overwriting a good cutoff with a blank would be worse than not moving it.
    $from = route('admin.badges.index', ['filter' => ['approved_from' => '2026-08-08T09:00']]);

    $response = actingAs($this->admin)
        ->from($from)
        ->post(route('admin.badges.bulk.print'), [
            'ids' => [$badge->id],
            'printer_id' => $this->printer->id,
        ]);

    $response->assertRedirect($from);
});
