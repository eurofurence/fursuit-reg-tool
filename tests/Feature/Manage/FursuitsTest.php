<?php

/*
 * Fursuits.
 *
 * This is the busiest surface in the panel and the one with the most surprising current
 * behaviour, so the assertions below are split three ways:
 *
 *  - parity: the seven columns, the Pending default filter, the confirm copy, the three
 *    notification titles and the eight rejection reasons, all verbatim;
 *  - the state machine: every status change goes through a transition, so approved_at,
 *    rejected_at, the activity entries and the owner's mail follow from it rather than
 *    being restated here;
 *  - the plan's deliberate changes: the claim lock is gone in favour of advisory presence,
 *    the queue walk is ordered and event-scoped, refusals speak, and a verdict waits out
 *    an undo window before the attendee hears about it.
 *
 * The partial-visit test is the one that catches a broken envelope. Asserting that a
 * column is declared sortable proves nothing; asserting that the visit the client
 * actually sends (X-Inertia plus X-Inertia-Partial-Data) comes back with data in all
 * five reloaded keys is what proves sorting works.
 */

use App\Enum\FursuitReviewOutcomeEnum;
use App\Http\Controllers\Manage\FursuitController;
use App\Http\Controllers\Manage\FursuitModerationController;
use App\Http\Controllers\Manage\FursuitNotificationController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\GenerateFursuitWebpJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\ReviewReason;
use App\Models\Species;
use App\Models\User;
use App\Notifications\FursuitApprovedNotification;
use App\Notifications\FursuitRejectedNotification;
use App\Notifications\FursuitRejectionReversedNotification;
use App\Policies\ActivityPolicy;
use App\Policies\FursuitPolicy;
use App\Services\FursuitPresence;
use App\Services\FursuitReviewService;
use App\Support\Manage\Action;
use App\Support\Manage\EventScope;
use App\Support\Manage\Filter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    Storage::fake('s3');
    Notification::fake();

    // ends_at in the future, because PendingToApproved and PendingToRejected only notify
    // while the event is still running.
    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
    ]);
    $this->otherEvent = Event::factory()->create([
        'name' => 'Eurofurence 28',
        'starts_at' => now()->subYear(),
        'ends_at' => now()->subYear()->addDays(5),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->outsider = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->species = Species::create(['name' => 'Wolf']);

    /*
     * Every fursuit gets a main badge. FursuitApprovedNotification and
     * FursuitRejectedNotification both read badges()->whereNull('extra_copy_of')->first()
     * into a property typed Badge in their constructor, so a fursuit without one cannot be
     * approved or rejected at all - which is the shape production data has, since ordering
     * a badge is what creates the fursuit in the first place.
     */
    $this->fursuit = function (array $attributes = []) {
        $fursuit = Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'species_id' => $this->species->id,
            'status' => Pending::$name,
            'name' => 'Fluffy',
            'image' => 'fursuits/fluffy.jpg',
            'published' => true,
            'catch_em_all' => true,
            ...$attributes,
        ]);

        Badge::factory()->create(['fursuit_id' => $fursuit->id, 'extra_copy_of' => null]);

        /*
         * Stamp the gallery renders on, because the queue holds back a record whose render is still
         * in flight (Fursuit::imageRenderSettled) and a fixture is meant to describe the settled
         * case. It has to happen after create and quietly: FursuitObserver clears the variants when
         * the photo changes, and GenerateFursuitWebpJob - running sync against a faked disk - writes
         * nothing back. A test that wants the few seconds after an upload nulls them again itself.
         */
        $fursuit->forceFill([
            'image_webp' => GenerateFursuitWebpJob::pathFor($fursuit->image),
            'image_thumb' => GenerateFursuitWebpJob::thumbPathFor($fursuit->image),
        ])->saveQuietly();

        return $fursuit->refresh();
    };

    $this->scoped = fn (User $user, ?int $eventId) => actingAs($user)->withSession([
        EventScope::SESSION_ID => $eventId,
        EventScope::SESSION_CHOSEN => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| List
|--------------------------------------------------------------------------
*/

test('the list renders the seven columns in order, with their labels', function () {
    ($this->fursuit)();

    ($this->scoped)($this->admin, null)->get(route('admin.fursuits.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Fursuits/Index')
            ->where('columns.0', fn ($c) => $c['key'] === 'user_name' && $c['label'] === 'By' && $c['sortable'])
            ->where('columns.1', fn ($c) => $c['key'] === 'species_name' && $c['label'] === 'Species.name' && $c['sortable'])
            ->where('columns.2', fn ($c) => $c['key'] === 'status' && $c['label'] === 'Status' && $c['type'] === 'badge')
            ->where('columns.3', fn ($c) => $c['key'] === 'name' && $c['label'] === 'Name')
            ->where('columns.4', fn ($c) => $c['key'] === 'image' && $c['label'] === 'Image' && $c['type'] === 'image')
            ->where('columns.5', fn ($c) => $c['key'] === 'published' && $c['label'] === 'Published' && $c['type'] === 'bool')
            ->where('columns.6', fn ($c) => $c['key'] === 'catch_em_all' && $c['label'] === 'Catch em all' && $c['type'] === 'bool')
            /*
             * The eighth column is the publication verdict, which is not the same
             * information as `published`: that one is the attendee's request and this one is
             * the reviewer's veto over it. Reading only `published` would show a blocked
             * fursuit as being in the gallery.
             */
            ->where('columns.7', fn ($c) => $c['key'] === 'publication_blocked' && $c['label'] === 'Gallery blocked' && $c['type'] === 'bool')
            ->count('columns', 8)
        );
});

test('the status filter opens on Pending and hides everything else until it is cleared', function () {
    // audit 135. The list has never shown the full set on first load, so a lost default
    // reads as missing data.
    $pending = ($this->fursuit)();
    $approved = ($this->fursuit)(['status' => Approved::$name, 'name' => 'Rex']);
    ($this->fursuit)(['status' => Rejected::$name, 'name' => 'Nope']);

    ($this->scoped)($this->admin, null)->get(route('admin.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('filters', 2)
            ->where('filters.0.key', 'status')
            ->where('filters.0.label', 'Status')
            ->where('filters.0.default', 'pending')
            ->where('filters.0.value', 'pending')
            ->where('filters.0.options', [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'rejected', 'label' => 'Rejected'],
            ])
            ->count('rows', 1)
            ->where('rows.0.id', $pending->id)
        );

    // Picking another value narrows to that one.
    ($this->scoped)($this->admin, null)
        ->get(route('admin.fursuits.index', ['filter' => ['status' => 'approved']]))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 1)->where('rows.0.id', $approved->id));

    // Clearing needs the explicit token: an absent key means "apply the default".
    ($this->scoped)($this->admin, null)
        ->get(route('admin.fursuits.index', ['filter' => ['status' => Filter::CLEARED]]))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 3)->where('filters.0.value', ''));
});

test('the list is scoped by the global event selector', function () {
    $mine = ($this->fursuit)();
    ($this->fursuit)(['event_id' => $this->otherEvent->id, 'name' => 'Elsewhere']);

    ($this->scoped)($this->admin, $this->event->id)->get(route('admin.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 1)->where('rows.0.id', $mine->id));

    ($this->scoped)($this->admin, null)->get(route('admin.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 2));
});

test('the cells carry the owner, species, state badge and a signed image url', function () {
    $owner = User::factory()->create(['name' => 'Ada']);
    $fursuit = ($this->fursuit)(['user_id' => $owner->id]);

    ($this->scoped)($this->admin, null)->get(route('admin.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.cells.user_name', 'Ada')
            ->where('rows.0.cells.species_name', 'Wolf')
            ->where('rows.0.cells.status', ['label' => 'Pending', 'tone' => 'warn', 'icon' => 'clock'])
            ->where('rows.0.cells.name', 'Fluffy')
            ->where('rows.0.cells.published', true)
            ->where('rows.0.cells.catch_em_all', true)
            ->where('rows.0.url', route('admin.fursuits.show', $fursuit))
        );

    // s3, not the default disk: every read site in the panel already assumed s3 while
    // the old panel upload wrote wherever the default pointed. The url is
    // signed and short-lived, so the assertion is on the object it points at.
    expect(FursuitController::imageUrl('fursuits/fluffy.jpg'))
        ->toContain('fursuits/fluffy.jpg')
        ->and(FursuitController::imageUrl(null))->toBeNull()
        ->and(FursuitController::imageUrl(''))->toBeNull();
});

test('a fursuit with no owner still renders', function () {
    // `fursuits.user_id` became nullable in 2025_07_31_180103 while the By column reads
    // straight through the relation, so an ownerless row has to be an empty cell rather
    // than a 500. `species_id` is still NOT NULL, so there is no null species to cover.
    $fursuit = ($this->fursuit)();
    Fursuit::whereKey($fursuit->id)->update(['user_id' => null]);

    ($this->scoped)($this->admin, null)->get(route('admin.fursuits.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.cells.user_name', null)
            ->where('rows.0.cells.species_name', 'Wolf')
        );
});

test('the only row action is View, and the one page action is the review queue', function () {
    // ViewAction only: EditAction sits commented out in the resource, no bulk actions are
    // declared, and the create header action is refused by the policy.
    // The page action is new: without it the way into the queue is a URL you have to know,
    // and the list is where a reviewer lands.
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->admin, null)->get(route('admin.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('rows.0.actions', 1)
            ->where('rows.0.actions.0.name', 'view')
            ->where('rows.0.actions.0.label', 'View')
            ->where('rows.0.actions.0.url', route('admin.fursuits.show', $fursuit))
            ->count('bulkActions', 0)
            ->count('pageActions', 1)
            ->where('pageActions.0.name', 'review')
            ->where('pageActions.0.url', route('admin.fursuits.review'))
        );
});

test('the gallery-blocked filter separates the reviewer veto from the attendee switch', function () {
    // Both fursuits ask to be published. Only one has been vetoed, and `status` cannot tell
    // them apart, which is exactly why the column and the filter exist.
    $blocked = ($this->fursuit)(['status' => Approved::$name, 'name' => 'Vetoed']);
    $blocked->forceFill([
        'publication_blocked_at' => now(),
        'publication_block_reason' => 'Not a photo of a costume.',
    ])->save();

    $clear = ($this->fursuit)(['status' => Approved::$name, 'name' => 'Fine']);

    ($this->scoped)($this->admin, null)
        ->get(route('admin.fursuits.index', ['filter' => ['status' => Filter::CLEARED, 'publication_blocked' => '1']]))
        ->assertInertia(fn (Assert $page) => $page
            ->count('rows', 1)
            ->where('rows.0.id', $blocked->id)
            ->where('rows.0.cells.publication_blocked', true)
            // The attendee's own switch is untouched by the filter and still says "yes,
            // publish me". The block is the answer to it.
            ->where('rows.0.cells.published', true)
        );

    ($this->scoped)($this->admin, null)
        ->get(route('admin.fursuits.index', ['filter' => ['status' => Filter::CLEARED, 'publication_blocked' => '0']]))
        ->assertInertia(fn (Assert $page) => $page
            ->count('rows', 1)
            ->where('rows.0.id', $clear->id)
            ->where('rows.0.cells.publication_blocked', false)
        );
});

test('search matches the name and the stored state name', function () {
    ($this->fursuit)(['name' => 'Fluffy']);
    ($this->fursuit)(['name' => 'Sparky']);

    $found = ($this->scoped)($this->admin, null)
        ->get(route('admin.fursuits.index', ['search' => 'Spark']))
        ->viewData('page')['props']['rows'];

    expect(collect($found)->pluck('cells.name')->all())->toBe(['Sparky']);

    // The status column is the searchable one in the audit, and it searches the stored
    // state name rather than the rendered badge.
    $byState = ($this->scoped)($this->admin, null)
        ->get(route('admin.fursuits.index', ['search' => 'pending']))
        ->viewData('page')['props']['rows'];

    expect($byState)->toHaveCount(2);
});

test('sorting and paging survive the partial reload the client actually sends', function () {
    // useTableQuery visits with only=[rows,meta,filters,sort,search] and Inertia resolves
    // those by top-level key. Nested under one `table` prop all five come back null, the
    // client merges the nulls over live props, and every sort and page click is inert.
    $first = ($this->fursuit)(['user_id' => User::factory()->create(['name' => 'Aaron'])->id]);
    $last = ($this->fursuit)(['user_id' => User::factory()->create(['name' => 'Zoe'])->id]);

    $version = app(HandleInertiaRequests::class)->version(request());

    $partial = fn (array $query) => ($this->scoped)($this->admin, null)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Manage/Fursuits/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
        ])
        ->get(route('admin.fursuits.index', $query));

    $ascending = $partial(['sort' => 'user_name', 'dir' => 'asc'])->assertSuccessful();

    expect($ascending->json('props.sort'))->toBe(['key' => 'user_name', 'dir' => 'asc'])
        ->and($ascending->json('props.rows.0.id'))->toBe($first->id)
        ->and($ascending->json('props.meta.page'))->toBe(1)
        // The five requested keys have to carry data, and `filters` has to still hold
        // the Pending default rather than an empty list.
        ->and($ascending->json('props.filters.0.value'))->toBe('pending')
        ->and($ascending->json('props.search'))->toBe('');

    expect($partial(['sort' => 'user_name', 'dir' => 'desc'])->json('props.rows.0.id'))->toBe($last->id);

    // Species sorts through the same kind of correlated subquery.
    $other = Species::create(['name' => 'Aardvark']);
    $aardvark = ($this->fursuit)(['species_id' => $other->id]);

    expect($partial(['sort' => 'species_name', 'dir' => 'asc'])->json('props.rows.0.id'))->toBe($aardvark->id);

    $paged = $partial(['per_page' => 10, 'page' => 2]);

    expect($paged->json('props.rows'))->toBe([])
        ->and($paged->json('props.meta.perPage'))->toBe(10)
        ->and($paged->json('props.meta.page'))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| View page: infolist and actions
|--------------------------------------------------------------------------
*/

test('the view page ships the infolist content', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->admin)->get(route('admin.fursuits.show', $fursuit))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Fursuits/Show')
            ->where('fursuit.name', 'Fluffy')
            ->where('fursuit.species', 'Wolf')
            ->where('fursuit.published', true)
            ->where('fursuit.catch_em_all', true)
            ->where('fursuit.status', ['label' => 'Pending', 'tone' => 'warn', 'icon' => 'clock'])
            // The gallery webp, not the print master: the panel stopped signing archival files to
            // fill a preview. The fixture stamps the render on, so this is the variant path.
            ->where('fursuit.image', fn ($url) => str_contains((string) $url, '.webp'))
            // The publication verdict, which is the half of a review that `status` cannot
            // carry, and presence, which the old panel page never showed at all.
            ->where('fursuit.publication.blocked', false)
            ->where('fursuit.presence.others', [])
        );
});

test('the record page offers no verdicts, only the way into the queue', function () {
    /*
     * One review surface. This page used to carry Approve, Reject, Block from gallery, Lift gallery
     * block, Approve (Rejected) and Next Fursuit as well, so two screens could hand down the same
     * decision with different copy, different confirm dialogs and - because the queue owns the undo
     * window and the presence banner - different safety.
     *
     * The claim is gone too: a five-minute cache lock taken on page load
     * that then refused every verdict unless the caller held it. Presence replaced it, shown and
     * never enforced.
     */
    $fursuit = ($this->fursuit)();

    $actions = collect(
        actingAs($this->reviewer)->get(route('admin.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->keyBy('name');

    expect($actions->keys()->all())->not->toContain(
        'approve',
        'reject',
        'block-publication',
        'unblock-publication',
        'approve-rejected',
        'next',
        'claim',
        'unclaim',
    );

    // What is left is the mail-only tool and the link into the queue.
    expect($actions->keys()->all())->toContain('send-notification', 'review')
        ->and($actions['review']['url'])->toBe(route('admin.fursuits.review.show', $fursuit))
        ->and($actions['review']['method'])->toBe('get');
});

test('presence is advisory: it names the other reviewer and never refuses a verdict', function () {
    $fursuit = ($this->fursuit)();
    $next = ($this->fursuit)(['name' => 'Next in line']);

    // The reviewer opens the record, which is what registers presence.
    actingAs($this->reviewer)->get(route('admin.fursuits.show', $fursuit))->assertSuccessful();

    expect(FursuitPresence::isBusy($fursuit, $this->admin))->toBeTrue()
        ->and(FursuitPresence::others($fursuit, $this->admin))
        ->toBe([['id' => $this->reviewer->id, 'name' => $this->reviewer->name]])
        // Not "busy" for the person who is on it.
        ->and(FursuitPresence::isBusy($fursuit, $this->reviewer))->toBeFalse();

    // A second reviewer arriving by link is told, and can still decide.
    ($this->scoped)($this->admin, $this->event->id)->get(route('admin.fursuits.show', $fursuit))
        ->assertInertia(fn (Assert $page) => $page->where(
            'fursuit.presence.others.0.name',
            $this->reviewer->name,
        ));

    ($this->scoped)($this->admin, $this->event->id)
        ->post(route('admin.fursuits.approve', $fursuit))
        ->assertRedirect(route('admin.fursuits.review.show', $next));

    expect($fursuit->fresh()->status)->toBeInstanceOf(Approved::class);
});

test('presence expires on its own, so a dead browser frees the record', function () {
    // The lock it replaces held for five minutes whatever happened to the browser. An entry
    // here lives one TTL past the last heartbeat, and the page heartbeats every 15 seconds.
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->get(route('admin.fursuits.show', $fursuit));

    expect(FursuitPresence::isBusy($fursuit, $this->admin))->toBeTrue();

    $this->travel(FursuitPresence::TTL_SECONDS + 1)->seconds();

    expect(FursuitPresence::isBusy($fursuit, $this->admin))->toBeFalse()
        ->and(FursuitPresence::others($fursuit, $this->admin))->toBe([]);

    $this->travelBack();
});

/*
|--------------------------------------------------------------------------
| Approve and reject
|--------------------------------------------------------------------------
*/

test('Approve runs PendingToApproved and advances to the next pending fursuit', function () {
    $fursuit = ($this->fursuit)();
    $next = ($this->fursuit)(['name' => 'Next in line']);

    ($this->scoped)($this->reviewer, $this->event->id)
        ->post(route('admin.fursuits.approve', $fursuit))
        ->assertRedirect(route('admin.fursuits.review.show', $next));

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Approved::class)
        ->and($fursuit->approved_at)->not->toBeNull()
        ->and($fursuit->rejected_at)->toBeNull();

    expect(Activity::where('subject_id', $fursuit->id)->where('description', 'Fursuit approved')->exists())
        ->toBeTrue();

    /*
     * The mail waits out the undo window. Nothing is sent inside the reviewer's request any
     * more, which is what makes the arrow-back on a mis-click cost the attendee nothing;
     * `fursuits:deliver-review-decisions` sends it once `notify_at` has passed.
     */
    Notification::assertNothingSent();

    expect($fursuit->latestReviewDecision()->outcome)->toBe(FursuitReviewOutcomeEnum::Approved);

    $this->travel(FursuitReviewService::UNDO_WINDOW_MINUTES + 1)->minutes();
    $this->artisan('fursuits:deliver-review-decisions')->assertSuccessful();
    $this->travelBack();

    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);
});

test('Approve refuses, and says so, when the state has no room for it', function () {
    // plan 2.10 #43, audit 72: the old panel action logged an error and returned with no
    // operator feedback whatsoever. The refusal is no longer about a claim - there is none -
    // but about the record's own state.
    $fursuit = ($this->fursuit)(['status' => Approved::$name]);

    actingAs($this->reviewer)->post(route('admin.fursuits.approve', $fursuit))
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'danger',
            'title' => 'Nothing was decided',
            'body' => 'This fursuit cannot be approved from its current status.',
        ]);

    expect($fursuit->fresh()->reviewDecisions()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('Reject stores and mails only the custom reason, then advances', function () {
    $fursuit = ($this->fursuit)();
    $next = ($this->fursuit)(['name' => 'Next in line']);

    ($this->scoped)($this->reviewer, $this->event->id)
        ->post(route('admin.fursuits.reject', $fursuit), [
            // The picker only prefills the textarea; the textarea is what is sent. A rejection
            // slug, not `ai_generated`: "this is AI art" keeps the badge, so it lives on the
            // publication list and the two lists share no slugs.
            'reason' => 'hate_speech',
            'custom_reason' => 'Edited by the reviewer before sending.',
        ])
        ->assertRedirect(route('admin.fursuits.review.show', $next));

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Rejected::class)
        ->and($fursuit->rejected_at)->not->toBeNull()
        ->and($fursuit->approved_at)->toBeNull();

    $entry = Activity::where('subject_id', $fursuit->id)->where('description', 'Fursuit rejected')->sole();

    expect($entry->properties['reason'])->toBe('Edited by the reviewer before sending.');

    $this->travel(FursuitReviewService::UNDO_WINDOW_MINUTES + 1)->minutes();
    $this->artisan('fursuits:deliver-review-decisions')->assertSuccessful();
    $this->travelBack();

    Notification::assertSentTo(
        $fursuit->user,
        fn (FursuitRejectedNotification $notification) => $notification->reason === 'Edited by the reviewer before sending.',
    );
});

test('Reject requires a reason to send and refuses a state it cannot reach', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('admin.fursuits.reject', $fursuit), ['reason' => 'drugs'])
        ->assertSessionHasErrors('custom_reason');

    // An unknown key from the picker is refused rather than stored.
    actingAs($this->reviewer)->post(route('admin.fursuits.reject', $fursuit), [
        'reason' => 'not-a-listed-reason',
        'custom_reason' => 'Anything',
    ])->assertSessionHasErrors('reason');

    // A publication-block slug is not a rejection slug: each outcome validates against its
    // own list, so a verdict cannot be filed under another verdict's reason.
    actingAs($this->reviewer)->post(route('admin.fursuits.reject', $fursuit), [
        'reason' => 'no_costume',
        'custom_reason' => 'Anything',
    ])->assertSessionHasErrors('reason');

    expect($fursuit->fresh()->status)->toBeInstanceOf(Pending::class);

    // Already rejected: there is no second rejection to hand down.
    $rejected = ($this->fursuit)(['status' => Rejected::$name, 'name' => 'Already out']);

    actingAs($this->reviewer)->post(route('admin.fursuits.reject', $rejected), ['custom_reason' => 'Anything'])
        ->assertInertiaFlash('toast', [
            'tone' => 'danger',
            'title' => 'Nothing was decided',
            'body' => 'This fursuit cannot be rejected from its current status.',
        ]);
});

test('the rejection reasons come from the table the desk edits, keyword and body apart', function () {
    /*
     * They were a PHP list, so the persisted select value was the integer index 0-7: clearing the
     * select threw "Undefined array key" and reordering the array silently rewired every prefill
     *. They are now rows in `review_reasons`, edited in Settings, keyed
     * by slug - stable under reordering and under a rewording - and each carries two texts: the
     * keyword the queue puts on a chip and the body the attendee receives.
     */
    ReviewReason::query()->delete();

    ReviewReason::create([
        'outcome' => FursuitReviewOutcomeEnum::Rejected->value,
        'slug' => 'house_rule',
        'keyword' => 'Our own rule',
        'body' => 'The long text the attendee actually reads.',
        'sort_order' => 10,
        'is_active' => true,
    ]);

    // Not offered, so not in the picker - but the slug stays readable in a request log.
    ReviewReason::create([
        'outcome' => FursuitReviewOutcomeEnum::Rejected->value,
        'slug' => 'retired',
        'keyword' => 'Retired',
        'body' => 'Not offered any more.',
        'sort_order' => 20,
        'is_active' => false,
    ]);

    expect(FursuitModerationController::rejectReasonOptions())->toBe([
        ['value' => 'house_rule', 'label' => 'Our own rule', 'body' => 'The long text the attendee actually reads.'],
    ]);

    $fursuit = ($this->fursuit)();

    // A retired slug cannot be revived by a hand-made request.
    actingAs($this->reviewer)->post(route('admin.fursuits.reject', $fursuit), [
        'reason' => 'retired',
        'custom_reason' => 'Anything',
    ])->assertSessionHasErrors('reason');
});

test('the shipped reason lists split "cannot be handed out" from "not shown in the gallery"', function () {
    /*
     * The whole design in one assertion. A rejection costs the attendee their badge until they act,
     * so it is reserved for content Eurofurence cannot hand out at all - drugs, harassment and hate
     * speech, nudity. Everything else that is merely wrong for the gallery keeps the badge and
     * closes only the public surfaces.
     *
     * The pairs worth noticing, because both were rejections at some point and both were wrong:
     *
     *  - fetish items are a *publication* block (the RoC restricts them in public areas and the
     *    badge is still issued; the gallery stays PG-13), while nudity is a rejection;
     *  - a photo with no costume, artwork, AI art, a real animal or an identifiable person breaks no
     *    rule the badge carries into the convention, so all of them keep the badge.
     */
    expect(ReviewReason::pickerFor(FursuitReviewOutcomeEnum::Rejected)->pluck('slug')->all())
        ->toBe(['drugs', 'hate_speech', 'nudity']);

    expect(ReviewReason::pickerFor(FursuitReviewOutcomeEnum::PublicationBlocked)->pluck('slug')->all())
        ->toBe(['artwork', 'ai_generated', 'real_animal', 'no_costume', 'identifiable_human', 'fetish']);

    /*
     * A reason says what we found and stops there. The consequence - the badge is printed anyway,
     * the gallery opt-in is revoked, resubmission is possible until the card is printed - is
     * identical for all six and lives in FursuitPublicationBlockedNotification, because repeating
     * it across editable strings guarantees they drift apart.
     */
    ReviewReason::query()->get()->each(function (ReviewReason $reason) {
        expect($reason->body)->toStartWith('We determined that')
            ->and($reason->body)->not->toContain('printed')
            ->and($reason->body)->not->toContain('gallery');
    });

    // Image quality is not a reason at all: the attendee chose the photo and we print what they
    // sent. Nor is a prop weapon - the RoC bans carrying weapons on site, not photographing one.
    $everySlug = ReviewReason::query()->pluck('slug')->all();

    expect($everySlug)->not->toContain('low_quality', 'human', 'real_weapon', 'real_fur');
});

/*
|--------------------------------------------------------------------------
| Approve Rejected
|--------------------------------------------------------------------------
*/

test('Approve Rejected still apologises when posted, though no page offers it', function () {
    /*
     * The apology path. It is deliberately not a queue verdict: it stays on the record, it mails
     * immediately rather than behind the undo window, and the mail it sends is the rejection-reversal
     * one, which exists to say "we got that wrong" and goes out even after the event has ended.
     *
     * No screen offers it any more - the record page carries no review actions - so what is asserted
     * here is that the endpoint still behaves, since the edit form's status picker reaches the same
     * transition.
     */
    $fursuit = ($this->fursuit)(['status' => Rejected::$name]);

    actingAs($this->reviewer)->post(route('admin.fursuits.approve-rejected', $fursuit))
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Rejected fursuit approved successfully',
            'body' => null,
        ]);

    expect($fursuit->fresh()->status)->toBeInstanceOf(Approved::class);

    Notification::assertSentTo($fursuit->user, FursuitRejectionReversedNotification::class);
});

test('Approve Rejected is not offered, and refuses, on a record that is not rejected', function () {
    $fursuit = ($this->fursuit)();

    $names = collect(
        actingAs($this->reviewer)->get(route('admin.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->pluck('name')->all();

    expect($names)->not->toContain('approve-rejected');

    actingAs($this->reviewer)->post(route('admin.fursuits.approve-rejected', $fursuit))
        ->assertInertiaFlash('toast', [
            'tone' => 'danger',
            'title' => 'Nothing was approved',
            'body' => 'This fursuit is not rejected.',
        ]);

    expect($fursuit->fresh()->status)->toBeInstanceOf(Pending::class);
});

/*
|--------------------------------------------------------------------------
| Send Notification
|--------------------------------------------------------------------------
*/

test('Send Notification is always offered and mails without touching the state', function () {
    $fursuit = ($this->fursuit)(['status' => Approved::$name]);

    $action = collect(
        actingAs($this->reviewer)->get(route('admin.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->firstWhere('name', 'send-notification');

    expect($action['label'])->toBe('Send Notification')
        ->and($action['icon'])->toBe('mail')
        ->and($action['tone'])->toBe('info')
        // No confirmation and no visibility predicate, as today.
        ->and($action['confirm'])->toBeNull()
        ->and($action['fields'][0]['label'])->toBe('Notification Type')
        /*
         * The picker tracks the mails we actually send. It offered two of six for a long time, so the
         * desk could not re-send a publication block or a pickup notice at all - and `needsReason`
         * rides along so the form cannot disagree with the server about which need one.
         */
        ->and(collect($action['fields'][0]['options'])->pluck('value')->all())->toBe([
            'approved',
            'rejected',
            'publication_blocked',
            'rejection_reversed',
            'pickup_ready',
            'pickup_reminder',
        ])
        ->and(collect($action['fields'][0]['options'])->firstWhere('value', 'rejected')['needsReason'])->toBeTrue()
        ->and(collect($action['fields'][0]['options'])->firstWhere('value', 'publication_blocked')['needsReason'])->toBeTrue()
        ->and(collect($action['fields'][0]['options'])->firstWhere('value', 'approved')['needsReason'])->toBeFalse()
        ->and($action['fields'][1]['label'])->toBe('Rejection Reason (Required for Rejection)');

    actingAs($this->reviewer)->post(route('admin.fursuits.notify', $fursuit), [
        'notification_type' => 'approved',
    ])
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Approved notification sent',
            'body' => null,
        ]);

    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);

    expect($fursuit->fresh()->status)->toBeInstanceOf(Approved::class);
});

test('a rejection notification needs its reason and keeps the fallback string', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('admin.fursuits.notify', $fursuit), [
        'notification_type' => 'rejected',
    ])->assertSessionHasErrors('rejection_reason');

    actingAs($this->reviewer)->post(route('admin.fursuits.notify', $fursuit), [
        'notification_type' => 'rejected',
        'rejection_reason' => 'Please re-upload a photo.',
    ])
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Needs a change (rejected) notification sent',
            'body' => null,
        ]);

    Notification::assertSentTo(
        $fursuit->user,
        fn (FursuitRejectedNotification $notification) => $notification->reason === 'Please re-upload a photo.',
    );

    // The defensive default the action carries, verbatim. Unreachable while the reason
    // is required, and kept for the same reason it exists today.
    expect(FursuitNotificationController::NO_REASON)->toBe('No reason provided');
});

/*
|--------------------------------------------------------------------------
| The queue walk
|--------------------------------------------------------------------------
*/

test('Next Fursuit walks the queue in order, event-scoped, skipping records somebody is on', function () {
    // plan 2.10 #42 and 2.9, audit 76: `Fursuit::where('status','pending')->first()` is
    // unordered and unscoped, and the three-try loop redirected to the last candidate
    // whether or not anybody was on it.
    $current = ($this->fursuit)();
    $taken = ($this->fursuit)(['name' => 'Taken']);
    $free = ($this->fursuit)(['name' => 'Free']);
    $elsewhere = ($this->fursuit)(['name' => 'Other event', 'event_id' => $this->otherEvent->id]);
    ($this->fursuit)(['name' => 'Already done', 'status' => Approved::$name]);

    // Another reviewer is on `$taken`, so the queue hands out the one after it. Nothing is
    // locked: the skip is a courtesy, not a refusal.
    FursuitPresence::touch($taken, $this->admin);

    ($this->scoped)($this->reviewer, $this->event->id)
        ->get(route('admin.fursuits.next', $current))
        ->assertRedirect(route('admin.fursuits.review.show', $free));

    // "All events" reaches the other event's queue; a specific scope never does.
    ($this->scoped)($this->reviewer, $this->otherEvent->id)
        ->get(route('admin.fursuits.next', $current))
        ->assertRedirect(route('admin.fursuits.review.show', $elsewhere));
});

test('an empty queue lands on the list and says so', function () {
    $only = ($this->fursuit)();

    ($this->scoped)($this->reviewer, $this->event->id)
        ->get(route('admin.fursuits.next', $only))
        ->assertRedirect(route('admin.fursuits.index'))
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Nothing left to review',
            'body' => 'No pending fursuits are waiting in the selected event.',
        ]);
});

/*
|--------------------------------------------------------------------------
| Activity log
|--------------------------------------------------------------------------
*/

test('the activity list renders newest first with a By column and a timestamp', function () {
    $fursuit = ($this->fursuit)();

    activity()->performedOn($fursuit)->causedBy($this->admin)->log('Older entry');
    activity()->performedOn($fursuit)->causedBy($this->reviewer)->log('Newer entry');

    actingAs($this->admin)->get(route('admin.fursuits.show', $fursuit))
        ->assertInertia(fn (Assert $page) => $page
            ->where('name', 'fursuit-activities')
            ->where('columns.0', fn ($c) => $c['key'] === 'description' && $c['label'] === 'Description')
            ->where('columns.1', fn ($c) => $c['key'] === 'causer_name' && $c['label'] === 'By' && $c['sortable'])
            // audit 134: the relation manager had no defaultSort and no timestamp at all.
            ->where('columns.2', fn ($c) => $c['key'] === 'created_at' && $c['type'] === 'datetime')
            // Newest first by key, which on an append-only log is newest first by time
            // and, unlike the timestamp, cannot tie inside one second.
            ->where('sort', ['key' => 'id', 'dir' => 'desc'])
            ->where('rows.0.cells.description', 'Newer entry')
            ->where('rows.0.cells.causer_name', $this->reviewer->name)
            ->where('rows.0.cells.created_at.display', fn ($display) => is_string($display) && $display !== '')
            ->where('rows.1.cells.description', 'Older entry')
            ->where('rows.1.cells.causer_name', $this->admin->name)
            ->count('filters', 0)
        );
});

test('the activity list searches by causer and offers no way to change anything', function () {
    // plan 2.10 #12, audit 56: create, edit, delete and bulk delete were all enabled on
    // the relation manager, and `causer` was not set on a manual create.
    $fursuit = ($this->fursuit)();

    activity()->performedOn($fursuit)->causedBy($this->admin)->log('By the admin');
    activity()->performedOn($fursuit)->causedBy($this->reviewer)->log('By the reviewer');

    actingAs($this->admin)
        ->get(route('admin.fursuits.show', ['fursuit' => $fursuit, 'search' => $this->reviewer->name]))
        ->assertInertia(fn (Assert $page) => $page
            ->count('rows', 1)
            ->where('rows.0.cells.description', 'By the reviewer')
            ->count('bulkActions', 0)
            ->count('pageActions', 0)
            ->count('rows.0.actions', 0)
        );

    expect(Gate::forUser($this->admin)->allows('create', Activity::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', Activity::first()))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', Activity::first()))->toBeFalse();
});

test('the logged attributes and the three transition entries are unchanged', function () {
    // The model logs a narrow subset by design; the three manual entries the transitions
    // write are what the reviewer actually reads in this list.
    expect((new Fursuit)->getActivitylogOptions()->logAttributes)
        ->toBe(['name', 'image', 'species_id']);

    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('admin.fursuits.reject', $fursuit), [
        'custom_reason' => 'The name of the fursuit is not appropriate.',
    ]);
    actingAs($this->reviewer)->post(route('admin.fursuits.approve-rejected', $fursuit));

    $descriptions = Activity::where('subject_id', $fursuit->id)->pluck('description');

    expect($descriptions)->toContain('Fursuit rejected')
        ->and($descriptions)->toContain('Fursuit approved (was previously rejected)');

    $fresh = ($this->fursuit)(['name' => 'Second']);
    actingAs($this->reviewer)->post(route('admin.fursuits.approve', $fresh));

    expect(Activity::where('subject_id', $fresh->id)->pluck('description'))
        ->toContain('Fursuit approved');
});

test('the activity list only shows this fursuit', function () {
    $mine = ($this->fursuit)();
    $other = ($this->fursuit)(['name' => 'Other']);

    activity()->performedOn($mine)->causedBy($this->admin)->log('Mine');
    activity()->performedOn($other)->causedBy($this->admin)->log('Theirs');

    $descriptions = collect(
        actingAs($this->admin)->get(route('admin.fursuits.show', $mine))
            ->viewData('page')['props']['rows']
    )->pluck('cells.description');

    expect($descriptions)->toContain('Mine')
        ->and($descriptions)->not->toContain('Theirs');
});

/*
|--------------------------------------------------------------------------
| Edit form
|--------------------------------------------------------------------------
*/

test('the edit form prefills the record and offers only the allowed transitions', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->admin)->get(route('admin.fursuits.edit', $fursuit))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Fursuits/Form')
            ->where('fursuit.name', 'Fluffy')
            ->where('fursuit.status', 'pending')
            ->where('fursuit.image', 'fursuits/fluffy.jpg')
            ->where('fursuit.published', true)
            // plan 2.10 #9: a picker over the machine's edges, not a free TextInput.
            ->where('transitions', [
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'rejected', 'label' => 'Rejected'],
            ])
            // plan 2.6: a relation select, not a numeric TextInput.
            ->count('events', 2)
            ->where('uploadPurpose', 'fursuit_image')
            // EditFursuit's own header: View plus the old panel's default delete copy.
            ->where('actions.0.name', 'view')
            ->where('actions.1.confirm', [
                'heading' => 'Delete fursuit',
                'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
                'submit' => 'Delete',
            ])
        );

    // A rejected record offers the two recovery edges instead.
    $rejected = ($this->fursuit)(['status' => Rejected::$name]);

    actingAs($this->admin)->get(route('admin.fursuits.edit', $rejected))
        ->assertInertia(fn (Assert $page) => $page->where('transitions', [
            ['value' => 'pending', 'label' => 'Pending'],
            ['value' => 'approved', 'label' => 'Approved'],
        ]));

    // An approved record offers none: the machine has no edge out of Approved.
    $approved = ($this->fursuit)(['status' => Approved::$name]);

    actingAs($this->admin)->get(route('admin.fursuits.edit', $approved))
        ->assertInertia(fn (Assert $page) => $page->where('transitions', []));
});

test('saving the form writes the plain attributes and leaves an unchanged status alone', function () {
    $fursuit = ($this->fursuit)();
    $newOwner = User::factory()->create();
    $newSpecies = Species::create(['name' => 'Fox']);

    actingAs($this->admin)->put(route('admin.fursuits.update', $fursuit), [
        'user_id' => $newOwner->id,
        'species_id' => $newSpecies->id,
        'event_id' => $this->otherEvent->id,
        'name' => 'Renamed',
        'image' => 'fursuits/renamed.jpg',
        'published' => false,
        'catch_em_all' => false,
        'status' => 'pending',
    ])
        ->assertRedirect(route('admin.fursuits.show', $fursuit))
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Saved', 'body' => null]);

    $fursuit->refresh();

    expect($fursuit->name)->toBe('Renamed')
        ->and($fursuit->user_id)->toBe($newOwner->id)
        ->and($fursuit->species_id)->toBe($newSpecies->id)
        ->and($fursuit->event_id)->toBe($this->otherEvent->id)
        ->and($fursuit->published)->toBeFalse()
        ->and($fursuit->catch_em_all)->toBeFalse()
        ->and($fursuit->status)->toBeInstanceOf(Pending::class)
        // Re-saving the same status is not a transition, so nothing was stamped.
        ->and($fursuit->approved_at)->toBeNull();

    Notification::assertNothingSent();
});

test('the form changes status through the state machine, never by writing the column', function () {
    // plan 2.10 #9, audit 21: a TextInput with maxLength(255) writing straight through
    // the cast, so no transition ran, no timestamp was stamped, no activity entry was
    // written and no user was notified.
    $fursuit = ($this->fursuit)();

    $payload = [
        'user_id' => $fursuit->user_id,
        'species_id' => $fursuit->species_id,
        'event_id' => $fursuit->event_id,
        'name' => $fursuit->name,
        'image' => $fursuit->image,
        'published' => true,
        'catch_em_all' => true,
    ];

    actingAs($this->admin)->put(route('admin.fursuits.update', $fursuit), $payload + ['status' => 'approved'])
        ->assertRedirect(route('admin.fursuits.show', $fursuit));

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Approved::class)
        ->and($fursuit->approved_at)->not->toBeNull();

    expect(Activity::where('subject_id', $fursuit->id)->where('description', 'Fursuit approved')->exists())
        ->toBeTrue();

    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);
});

test('the form refuses a status the machine has no edge for, and rejection needs a reason', function () {
    $approved = ($this->fursuit)(['status' => Approved::$name]);

    $payload = fn (Fursuit $fursuit) => [
        'user_id' => $fursuit->user_id,
        'species_id' => $fursuit->species_id,
        'event_id' => $fursuit->event_id,
        'name' => $fursuit->name,
        'image' => $fursuit->image,
        'published' => true,
        'catch_em_all' => true,
    ];

    // Approved has no outgoing edge, so nothing but "approved" is accepted.
    actingAs($this->admin)
        ->put(route('admin.fursuits.update', $approved), $payload($approved) + ['status' => 'pending'])
        ->assertSessionHasErrors('status');

    $pending = ($this->fursuit)();

    actingAs($this->admin)
        ->put(route('admin.fursuits.update', $pending), $payload($pending) + ['status' => 'rejected'])
        ->assertSessionHasErrors('rejection_reason');

    actingAs($this->admin)
        ->put(route('admin.fursuits.update', $pending), $payload($pending) + [
            'status' => 'rejected',
            'rejection_reason' => 'The name of the fursuit is not appropriate.',
        ])
        ->assertRedirect();

    $pending->refresh();

    expect($pending->status)->toBeInstanceOf(Rejected::class)
        ->and($pending->rejected_at)->not->toBeNull();

    Notification::assertSentTo(
        $pending->user,
        fn (FursuitRejectedNotification $notification) => $notification->reason === 'The name of the fursuit is not appropriate.',
    );
});

test('approved_at and rejected_at cannot be written by hand any more', function () {
    // plan 2.10 #9: both were DateTimePickers that could contradict `status`.
    $fursuit = ($this->fursuit)();

    actingAs($this->admin)->put(route('admin.fursuits.update', $fursuit), [
        'user_id' => $fursuit->user_id,
        'species_id' => $fursuit->species_id,
        'event_id' => $fursuit->event_id,
        'name' => $fursuit->name,
        'image' => $fursuit->image,
        'published' => true,
        'catch_em_all' => true,
        'approved_at' => now()->toDateTimeString(),
        'rejected_at' => now()->toDateTimeString(),
    ])->assertRedirect();

    $fursuit->refresh();

    expect($fursuit->approved_at)->toBeNull()
        ->and($fursuit->rejected_at)->toBeNull();
});

test('the form validates the fields the old panel schema required', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->admin)->put(route('admin.fursuits.update', $fursuit), [])
        ->assertSessionHasErrors(['user_id', 'species_id', 'event_id', 'name', 'image', 'published', 'catch_em_all']);

    actingAs($this->admin)->put(route('admin.fursuits.update', $fursuit), [
        'user_id' => 999999,
        'species_id' => 999999,
        'event_id' => 999999,
        'name' => str_repeat('a', 256),
        'image' => 'fursuits/x.jpg',
        'published' => true,
        'catch_em_all' => true,
    ])->assertSessionHasErrors(['user_id', 'species_id', 'event_id', 'name']);
});

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

test('a fursuit is soft deleted and disappears from the list', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->admin)->delete(route('admin.fursuits.destroy', $fursuit))
        ->assertRedirect(route('admin.fursuits.index'))
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Deleted', 'body' => null]);

    expect(Fursuit::whereKey($fursuit->id)->exists())->toBeFalse()
        ->and(Fursuit::withTrashed()->whereKey($fursuit->id)->exists())->toBeTrue()
        // FursuitObserver::deleting cascades the soft delete to the fursuit's badges, so
        // none is left orphaned with a null deleted_at.
        ->and(Badge::where('fursuit_id', $fursuit->id)->exists())->toBeFalse()
        ->and(Badge::withTrashed()->where('fursuit_id', $fursuit->id)->exists())->toBeTrue();

    ($this->scoped)($this->admin, null)->get(route('admin.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 0));
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

test('a reviewer works the queue but cannot edit or delete a record', function () {
    // The whole moderation surface is gated on `view`, which a reviewer holds, while
    // editing the row itself stays admin-only. That is the split the old panel page had.
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer);

    get(route('admin.fursuits.index'))->assertSuccessful();
    get(route('admin.fursuits.show', $fursuit))->assertSuccessful();
    get(route('admin.fursuits.review.show', $fursuit))->assertSuccessful();
    get(route('admin.fursuits.next', $fursuit))->assertRedirect();

    get(route('admin.fursuits.edit', $fursuit))->assertForbidden();
    put(route('admin.fursuits.update', $fursuit), [])->assertForbidden();
    delete(route('admin.fursuits.destroy', $fursuit))->assertForbidden();

    expect(Fursuit::whereKey($fursuit->id)->exists())->toBeTrue();
});

test('a user with neither flag is shut out of the module', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->outsider);

    get(route('admin.fursuits.index'))->assertForbidden();
    get(route('admin.fursuits.show', $fursuit))->assertForbidden();
    get(route('admin.fursuits.review.show', $fursuit))->assertForbidden();
    post(route('admin.fursuits.approve', $fursuit))->assertForbidden();
    post(route('admin.fursuits.block-publication', $fursuit), ['custom_reason' => 'x'])->assertForbidden();
    post(route('admin.fursuits.reject', $fursuit), ['custom_reason' => 'x'])->assertForbidden();
    post(route('admin.fursuits.notify', $fursuit), ['notification_type' => 'approved'])->assertForbidden();
});

test('no create route exists and the policy still refuses creation', function () {
    // plan 2.2, audit 38: create() returning false is not a bug to fix during a rewrite.
    expect(Gate::forUser($this->admin)->allows('create', Fursuit::class))->toBeFalse()
        ->and(app('router')->getRoutes()->getByName('admin.fursuits.create'))->toBeNull();
});

test('both policies are registered', function () {
    // FursuitPolicy is found by discovery today; ActivityPolicy never could be, because
    // the model lives in the Spatie package namespace, so without the explicit mapping
    // every ability on the audit trail falls open.
    expect(Gate::getPolicyFor(Fursuit::class))->toBeInstanceOf(FursuitPolicy::class)
        ->and(Gate::getPolicyFor(Activity::class))->toBeInstanceOf(ActivityPolicy::class);
});

test('the rail links to the module and its chip counts pending fursuits', function () {
    // plan 2.8: the chip showed the total fursuit count coloured by the pending count,
    // two different numbers behind one chip. It is the pending count now.
    ($this->fursuit)();
    ($this->fursuit)(['status' => Approved::$name]);

    ($this->scoped)($this->admin, $this->event->id)->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageNav', function ($groups) {
            $item = collect($groups)
                ->flatMap(fn ($group) => $group['items'])
                ->firstWhere('label', 'Fursuits');

            return $item !== null
                && $item['url'] === route('admin.fursuits.index')
                && $item['badge'] === ['label' => '1', 'tone' => 'warn'];
        }));
});
