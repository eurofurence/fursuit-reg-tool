<?php

/*
 * Fursuits (plan phase 3, audit 4.3, 4.3.1, 4.3.3, 4.3.4, 4.3.5).
 *
 * This is the busiest surface in the panel and the one with the most surprising current
 * behaviour, so the assertions below are split three ways:
 *
 *  - parity: the seven columns, the Pending default filter, the confirm copy, the three
 *    notification titles and the eight rejection reasons, all verbatim;
 *  - the state machine: every status change goes through a transition, so approved_at,
 *    rejected_at, the activity entries and the owner's mail follow from it rather than
 *    being restated here;
 *  - the plan's deliberate changes: claiming is explicit, unclaiming checks ownership,
 *    the queue walk is ordered and event-scoped, and refusals speak.
 *
 * The partial-visit test is the one that catches a broken envelope. Asserting that a
 * column is declared sortable proves nothing; asserting that the visit the client
 * actually sends (X-Inertia plus X-Inertia-Partial-Data) comes back with data in all
 * five reloaded keys is what proves sorting works.
 */

use App\Http\Controllers\Manage\FursuitController;
use App\Http\Controllers\Manage\FursuitModerationController;
use App\Http\Controllers\Manage\FursuitNotificationController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use App\Notifications\FursuitApprovedNotification;
use App\Notifications\FursuitRejectedNotification;
use App\Notifications\FursuitRejectionReversedNotification;
use App\Policies\ActivityPolicy;
use App\Policies\FursuitPolicy;
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

        return $fursuit;
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

    ($this->scoped)($this->admin, null)->get(route('manage.fursuits.index'))
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
            ->count('columns', 7)
        );
});

test('the status filter opens on Pending and hides everything else until it is cleared', function () {
    // audit 135. The list has never shown the full set on first load, so a lost default
    // reads as missing data.
    $pending = ($this->fursuit)();
    $approved = ($this->fursuit)(['status' => Approved::$name, 'name' => 'Rex']);
    ($this->fursuit)(['status' => Rejected::$name, 'name' => 'Nope']);

    ($this->scoped)($this->admin, null)->get(route('manage.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('filters', 1)
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
        ->get(route('manage.fursuits.index', ['filter' => ['status' => 'approved']]))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 1)->where('rows.0.id', $approved->id));

    // Clearing needs the explicit token: an absent key means "apply the default".
    ($this->scoped)($this->admin, null)
        ->get(route('manage.fursuits.index', ['filter' => ['status' => Filter::CLEARED]]))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 3)->where('filters.0.value', ''));
});

test('the list is scoped by the global event selector', function () {
    $mine = ($this->fursuit)();
    ($this->fursuit)(['event_id' => $this->otherEvent->id, 'name' => 'Elsewhere']);

    ($this->scoped)($this->admin, $this->event->id)->get(route('manage.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 1)->where('rows.0.id', $mine->id));

    ($this->scoped)($this->admin, null)->get(route('manage.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 2));
});

test('the cells carry the owner, species, state badge and a signed image url', function () {
    $owner = User::factory()->create(['name' => 'Ada']);
    $fursuit = ($this->fursuit)(['user_id' => $owner->id]);

    ($this->scoped)($this->admin, null)->get(route('manage.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.cells.user_name', 'Ada')
            ->where('rows.0.cells.species_name', 'Wolf')
            ->where('rows.0.cells.status', ['label' => 'Pending', 'tone' => 'warn', 'icon' => 'clock'])
            ->where('rows.0.cells.name', 'Fluffy')
            ->where('rows.0.cells.published', true)
            ->where('rows.0.cells.catch_em_all', true)
            ->where('rows.0.url', route('manage.fursuits.show', $fursuit))
        );

    // s3, not the default disk: every read site in the panel already assumed s3 while
    // the Filament upload wrote wherever the default pointed (audit 7.4). The url is
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

    ($this->scoped)($this->admin, null)->get(route('manage.fursuits.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.cells.user_name', null)
            ->where('rows.0.cells.species_name', 'Wolf')
        );
});

test('the only row action is View, and there are no bulk or page actions', function () {
    // ViewAction only: EditAction sits commented out in the resource, no bulk actions are
    // declared, and the create header action is refused by the policy (audit 4.3, 38).
    $fursuit = ($this->fursuit)();

    ($this->scoped)($this->admin, null)->get(route('manage.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('rows.0.actions', 1)
            ->where('rows.0.actions.0.name', 'view')
            ->where('rows.0.actions.0.label', 'View')
            ->where('rows.0.actions.0.url', route('manage.fursuits.show', $fursuit))
            ->count('bulkActions', 0)
            ->count('pageActions', 0)
        );
});

test('search matches the name and the stored state name', function () {
    ($this->fursuit)(['name' => 'Fluffy']);
    ($this->fursuit)(['name' => 'Sparky']);

    $found = ($this->scoped)($this->admin, null)
        ->get(route('manage.fursuits.index', ['search' => 'Spark']))
        ->viewData('page')['props']['rows'];

    expect(collect($found)->pluck('cells.name')->all())->toBe(['Sparky']);

    // The status column is the searchable one in the audit, and it searches the stored
    // state name rather than the rendered badge.
    $byState = ($this->scoped)($this->admin, null)
        ->get(route('manage.fursuits.index', ['search' => 'pending']))
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
        ->get(route('manage.fursuits.index', $query));

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

    actingAs($this->admin)->get(route('manage.fursuits.show', $fursuit))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Fursuits/Show')
            ->where('fursuit.name', 'Fluffy')
            ->where('fursuit.species', 'Wolf')
            ->where('fursuit.published', true)
            ->where('fursuit.catch_em_all', true)
            ->where('fursuit.status', ['label' => 'Pending', 'tone' => 'warn', 'icon' => 'clock'])
            ->where('fursuit.image', fn ($url) => str_contains((string) $url, 'fursuits/fluffy.jpg'))
            // The claim state, which the Filament page never showed at all.
            ->where('fursuit.claim.claimed', false)
            ->where('fursuit.claim.mine', false)
        );
});

test('opening a pending fursuit does not claim it', function () {
    // plan 2.10 #41, audit 69: `public $defaultAction = 'Claim'` mounted the action on
    // every page load, so merely looking at a record took the lock.
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))->assertSuccessful();

    expect($fursuit->isClaimed())->toBeFalse();
});

test('Claim and Unclaim are offered on the state they belong to', function () {
    $fursuit = ($this->fursuit)();

    $actions = fn () => collect(
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->keyBy('name');

    expect($actions()->keys()->all())->toContain('claim')
        ->and($actions()->keys()->all())->not->toContain('unclaim', 'approve', 'reject');

    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fursuit))->assertRedirect();

    $claimed = $actions();

    expect($claimed->keys()->all())->toContain('unclaim', 'approve', 'reject')
        ->and($claimed->keys()->all())->not->toContain('claim')
        ->and($claimed['approve']['icon'])->toBe('circle-check')
        ->and($claimed['approve']['tone'])->toBe('ok')
        ->and($claimed['reject']['icon'])->toBe('circle-x')
        ->and($claimed['reject']['tone'])->toBe('danger')
        ->and($claimed['unclaim']['tone'])->toBe('danger');
});

test('a claim is not silently inherited by a second reviewer', function () {
    // audit 70. The mechanism is unchanged - one cache key per fursuit with a five
    // minute TTL - but the two gestures around it are now explicit and checked.
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fursuit))->assertRedirect();

    expect($fursuit->isClaimedBySelf($this->reviewer))->toBeTrue()
        ->and($fursuit->isClaimedBySelf($this->admin))->toBeFalse();

    // The second reviewer is moved on rather than handed the same record.
    $second = ($this->fursuit)(['name' => 'Second']);

    ($this->scoped)($this->admin, $this->event->id)
        ->post(route('manage.fursuits.claim', $fursuit))
        ->assertRedirect(route('manage.fursuits.show', $second))
        ->assertInertiaFlash('toast', [
            'tone' => 'warning',
            'title' => 'Already claimed',
            'body' => 'Another reviewer is working on this fursuit.',
        ]);

    expect($fursuit->isClaimedBySelf($this->reviewer))->toBeTrue();
});

test('unclaim refuses to drop somebody else\'s claim', function () {
    // plan 2.10 #41, audit 71: Fursuit::unclaim() takes no parameter and checks nothing,
    // so anyone could drop anyone's lock.
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fursuit))->assertRedirect();

    actingAs($this->admin)->delete(route('manage.fursuits.unclaim', $fursuit))
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'danger',
            'title' => 'Nothing was unclaimed',
            'body' => 'This fursuit is not claimed by you.',
        ]);

    expect($fursuit->isClaimedBySelf($this->reviewer))->toBeTrue();

    actingAs($this->reviewer)->delete(route('manage.fursuits.unclaim', $fursuit))->assertRedirect();

    expect($fursuit->isClaimed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Approve and reject
|--------------------------------------------------------------------------
*/

test('Approve runs PendingToApproved and advances to the next pending fursuit', function () {
    $fursuit = ($this->fursuit)();
    $next = ($this->fursuit)(['name' => 'Next in line']);

    ($this->scoped)($this->reviewer, $this->event->id)->post(route('manage.fursuits.claim', $fursuit));

    ($this->scoped)($this->reviewer, $this->event->id)
        ->post(route('manage.fursuits.approve', $fursuit))
        ->assertRedirect(route('manage.fursuits.show', $next));

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Approved::class)
        ->and($fursuit->approved_at)->not->toBeNull()
        ->and($fursuit->rejected_at)->toBeNull();

    expect(Activity::where('subject_id', $fursuit->id)->where('description', 'Fursuit approved')->exists())
        ->toBeTrue();

    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);
});

test('Approve refuses, and says so, when the record is not claimed by you', function () {
    // plan 2.10 #43, audit 72: both actions logged an error and returned with no
    // operator feedback whatsoever.
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('manage.fursuits.approve', $fursuit))
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'danger',
            'title' => 'Nothing was approved',
            'body' => 'Claim this fursuit before approving it.',
        ]);

    expect($fursuit->fresh()->status)->toBeInstanceOf(Pending::class);
    Notification::assertNothingSent();
});

test('Reject stores and mails only the custom reason, then advances', function () {
    $fursuit = ($this->fursuit)();
    $next = ($this->fursuit)(['name' => 'Next in line']);

    ($this->scoped)($this->reviewer, $this->event->id)->post(route('manage.fursuits.claim', $fursuit));

    ($this->scoped)($this->reviewer, $this->event->id)
        ->post(route('manage.fursuits.reject', $fursuit), [
            // The picker only prefills the textarea; the textarea is what is sent.
            'reason' => 'ai_generated',
            'custom_reason' => 'Edited by the reviewer before sending.',
        ])
        ->assertRedirect(route('manage.fursuits.show', $next));

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Rejected::class)
        ->and($fursuit->rejected_at)->not->toBeNull()
        ->and($fursuit->approved_at)->toBeNull();

    $entry = Activity::where('subject_id', $fursuit->id)->where('description', 'Fursuit rejected')->sole();

    expect($entry->properties['reason'])->toBe('Edited by the reviewer before sending.');

    Notification::assertSentTo(
        $fursuit->user,
        fn (FursuitRejectedNotification $notification) => $notification->reason === 'Edited by the reviewer before sending.',
    );
});

test('Reject requires a reason to send and refuses an unclaimed record', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fursuit));

    actingAs($this->reviewer)->post(route('manage.fursuits.reject', $fursuit), ['reason' => 'name'])
        ->assertSessionHasErrors('custom_reason');

    // An unknown key from the picker is refused rather than stored.
    actingAs($this->reviewer)->post(route('manage.fursuits.reject', $fursuit), [
        'reason' => 'not-a-listed-reason',
        'custom_reason' => 'Anything',
    ])->assertSessionHasErrors('reason');

    actingAs($this->reviewer)->delete(route('manage.fursuits.unclaim', $fursuit));

    actingAs($this->reviewer)->post(route('manage.fursuits.reject', $fursuit), ['custom_reason' => 'Anything'])
        ->assertInertiaFlash('toast', [
            'tone' => 'danger',
            'title' => 'Nothing was rejected',
            'body' => 'Claim this fursuit before rejecting it.',
        ]);

    expect($fursuit->fresh()->status)->toBeInstanceOf(Pending::class);
});

test('the eight rejection reasons ship verbatim, as a keyed list', function () {
    // plan 2.10 #40, audit 37: a PHP list today, so the persisted value was the integer
    // index 0-7, clearing the select threw "Undefined array key", and reordering the
    // array silently rewired every prefill.
    expect(array_values(FursuitModerationController::REJECT_REASONS))->toBe([
        'The submission shows a human. We can only accept badges created for fursuits.',
        'The submission is explicit and does not follow our guidelines.',
        'The submission is of low quality and does not meet our guidelines.',
        'The submission is a not a photo. We only accept photos, we do not accept illustrations or other digital art as fursuit images.',
        'The submission shows an animal. We do not allow images of real animals, only fursuits.',
        'The submission is AI generated and does not show a real fursuit.',
        'The name of the fursuit is not appropriate.',
        'The species of the fursuit is not appropriate.',
    ]);

    foreach (array_keys(FursuitModerationController::REJECT_REASONS) as $key) {
        expect($key)->not->toBeNumeric();
    }

    $fursuit = ($this->fursuit)();
    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fursuit));

    $reject = collect(
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->firstWhere('name', 'reject');

    expect($reject['fields'][0]['key'])->toBe('reason')
        ->and($reject['fields'][0]['options'])->toHaveCount(8)
        ->and($reject['fields'][1]['key'])->toBe('custom_reason')
        ->and($reject['fields'][1]['label'])->toBe('Reason Sent to the User!')
        ->and($reject['fields'][1]['required'])->toBeTrue();
});

test('Approve and Reject carry the framework default confirm copy', function () {
    $fursuit = ($this->fursuit)();
    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fursuit));

    $actions = collect(
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->keyBy('name');

    expect($actions['approve']['confirm'])->toBe([
        'heading' => 'Approve',
        'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
        'submit' => 'Confirm',
    ])->and($actions['reject']['confirm'])->toBe([
        'heading' => 'Reject',
        'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
        'submit' => 'Confirm',
    ]);
});

/*
|--------------------------------------------------------------------------
| Approve Rejected
|--------------------------------------------------------------------------
*/

test('Approve Rejected needs no claim, keeps its custom copy and always notifies', function () {
    $fursuit = ($this->fursuit)(['status' => Rejected::$name]);

    $action = collect(
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->firstWhere('name', 'approve-rejected');

    expect($action['label'])->toBe('Approve (Rejected)')
        ->and($action['icon'])->toBe('circle-check')
        ->and($action['tone'])->toBe('ok')
        ->and($action['confirm'])->toBe([
            'heading' => 'Approve Rejected Fursuit',
            'description' => 'This will send an apology email to the user and approve the fursuit.',
            'submit' => 'Yes, approve it',
        ]);

    expect($fursuit->isClaimed())->toBeFalse();

    actingAs($this->reviewer)->post(route('manage.fursuits.approve-rejected', $fursuit))
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Rejected fursuit approved successfully',
            'body' => null,
        ]);

    $fursuit->refresh();

    expect($fursuit->status)->toBeInstanceOf(Approved::class)
        ->and($fursuit->approved_at)->not->toBeNull()
        ->and($fursuit->rejected_at)->toBeNull();

    expect(Activity::where('subject_id', $fursuit->id)
        ->where('description', 'Fursuit approved (was previously rejected)')
        ->exists())->toBeTrue();

    Notification::assertSentTo($fursuit->user, FursuitRejectionReversedNotification::class);
});

test('Approve Rejected is not offered, and refuses, on a record that is not rejected', function () {
    $fursuit = ($this->fursuit)();

    $names = collect(
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->pluck('name')->all();

    expect($names)->not->toContain('approve-rejected');

    actingAs($this->reviewer)->post(route('manage.fursuits.approve-rejected', $fursuit))
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
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->firstWhere('name', 'send-notification');

    expect($action['label'])->toBe('Send Notification')
        ->and($action['icon'])->toBe('mail')
        ->and($action['tone'])->toBe('info')
        // No confirmation and no visibility predicate, as today.
        ->and($action['confirm'])->toBeNull()
        ->and($action['fields'][0]['label'])->toBe('Notification Type')
        ->and($action['fields'][0]['options'])->toBe([
            ['value' => 'approved', 'label' => 'Approval Notification'],
            ['value' => 'rejected', 'label' => 'Rejection Notification'],
        ])
        ->and($action['fields'][1]['label'])->toBe('Rejection Reason (Required for Rejection)');

    actingAs($this->reviewer)->post(route('manage.fursuits.notify', $fursuit), [
        'notification_type' => 'approved',
    ])
        ->assertRedirect()
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Approval notification sent successfully',
            'body' => null,
        ]);

    Notification::assertSentTo($fursuit->user, FursuitApprovedNotification::class);

    expect($fursuit->fresh()->status)->toBeInstanceOf(Approved::class);
});

test('a rejection notification needs its reason and keeps the fallback string', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer)->post(route('manage.fursuits.notify', $fursuit), [
        'notification_type' => 'rejected',
    ])->assertSessionHasErrors('rejection_reason');

    actingAs($this->reviewer)->post(route('manage.fursuits.notify', $fursuit), [
        'notification_type' => 'rejected',
        'rejection_reason' => 'Please re-upload a photo.',
    ])
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Rejection notification sent successfully',
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

test('Next Fursuit walks the queue in order, event-scoped, skipping claimed records', function () {
    // plan 2.10 #42 and 2.9, audit 76: `Fursuit::where('status','pending')->first()` is
    // unordered and unscoped, and the three-try loop redirected to the last candidate
    // whether or not it was still claimed.
    $current = ($this->fursuit)();
    $claimed = ($this->fursuit)(['name' => 'Taken']);
    $free = ($this->fursuit)(['name' => 'Free']);
    $elsewhere = ($this->fursuit)(['name' => 'Other event', 'event_id' => $this->otherEvent->id]);
    ($this->fursuit)(['name' => 'Already done', 'status' => Approved::$name]);

    actingAs($this->admin)->post(route('manage.fursuits.claim', $claimed));

    ($this->scoped)($this->reviewer, $this->event->id)
        ->get(route('manage.fursuits.next', $current))
        ->assertRedirect(route('manage.fursuits.show', $free));

    // "All events" reaches the other event's queue; a specific scope never does.
    ($this->scoped)($this->reviewer, $this->otherEvent->id)
        ->get(route('manage.fursuits.next', $current))
        ->assertRedirect(route('manage.fursuits.show', $elsewhere));
});

test('an empty queue lands on the list and says so', function () {
    $only = ($this->fursuit)();

    ($this->scoped)($this->reviewer, $this->event->id)
        ->get(route('manage.fursuits.next', $only))
        ->assertRedirect(route('manage.fursuits.index'))
        ->assertInertiaFlash('toast', [
            'tone' => 'success',
            'title' => 'Nothing left to review',
            'body' => 'No pending fursuits are waiting in the selected event.',
        ]);
});

test('the Next Fursuit action is always offered', function () {
    $fursuit = ($this->fursuit)(['status' => Approved::$name]);

    $action = collect(
        actingAs($this->reviewer)->get(route('manage.fursuits.show', $fursuit))
            ->viewData('page')['props']['actions']
    )->firstWhere('name', 'next');

    expect($action['label'])->toBe('Next Fursuit')
        ->and($action['icon'])->toBe('arrow-right')
        ->and($action['method'])->toBe('get');
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

    actingAs($this->admin)->get(route('manage.fursuits.show', $fursuit))
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
        ->get(route('manage.fursuits.show', ['fursuit' => $fursuit, 'search' => $this->reviewer->name]))
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

    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fursuit));
    actingAs($this->reviewer)->post(route('manage.fursuits.reject', $fursuit), [
        'custom_reason' => 'The name of the fursuit is not appropriate.',
    ]);
    actingAs($this->reviewer)->post(route('manage.fursuits.approve-rejected', $fursuit));

    $descriptions = Activity::where('subject_id', $fursuit->id)->pluck('description');

    expect($descriptions)->toContain('Fursuit rejected')
        ->and($descriptions)->toContain('Fursuit approved (was previously rejected)');

    $fresh = ($this->fursuit)(['name' => 'Second']);
    actingAs($this->reviewer)->post(route('manage.fursuits.claim', $fresh));
    actingAs($this->reviewer)->post(route('manage.fursuits.approve', $fresh));

    expect(Activity::where('subject_id', $fresh->id)->pluck('description'))
        ->toContain('Fursuit approved');
});

test('the activity list only shows this fursuit', function () {
    $mine = ($this->fursuit)();
    $other = ($this->fursuit)(['name' => 'Other']);

    activity()->performedOn($mine)->causedBy($this->admin)->log('Mine');
    activity()->performedOn($other)->causedBy($this->admin)->log('Theirs');

    $descriptions = collect(
        actingAs($this->admin)->get(route('manage.fursuits.show', $mine))
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

    actingAs($this->admin)->get(route('manage.fursuits.edit', $fursuit))
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
            // EditFursuit's own header: View plus Filament's default delete copy.
            ->where('actions.0.name', 'view')
            ->where('actions.1.confirm', [
                'heading' => 'Delete fursuit',
                'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
                'submit' => 'Delete',
            ])
        );

    // A rejected record offers the two recovery edges instead.
    $rejected = ($this->fursuit)(['status' => Rejected::$name]);

    actingAs($this->admin)->get(route('manage.fursuits.edit', $rejected))
        ->assertInertia(fn (Assert $page) => $page->where('transitions', [
            ['value' => 'pending', 'label' => 'Pending'],
            ['value' => 'approved', 'label' => 'Approved'],
        ]));

    // An approved record offers none: the machine has no edge out of Approved.
    $approved = ($this->fursuit)(['status' => Approved::$name]);

    actingAs($this->admin)->get(route('manage.fursuits.edit', $approved))
        ->assertInertia(fn (Assert $page) => $page->where('transitions', []));
});

test('saving the form writes the plain attributes and leaves an unchanged status alone', function () {
    $fursuit = ($this->fursuit)();
    $newOwner = User::factory()->create();
    $newSpecies = Species::create(['name' => 'Fox']);

    actingAs($this->admin)->put(route('manage.fursuits.update', $fursuit), [
        'user_id' => $newOwner->id,
        'species_id' => $newSpecies->id,
        'event_id' => $this->otherEvent->id,
        'name' => 'Renamed',
        'image' => 'fursuits/renamed.jpg',
        'published' => false,
        'catch_em_all' => false,
        'status' => 'pending',
    ])
        ->assertRedirect(route('manage.fursuits.show', $fursuit))
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

    actingAs($this->admin)->put(route('manage.fursuits.update', $fursuit), $payload + ['status' => 'approved'])
        ->assertRedirect(route('manage.fursuits.show', $fursuit));

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
        ->put(route('manage.fursuits.update', $approved), $payload($approved) + ['status' => 'pending'])
        ->assertSessionHasErrors('status');

    $pending = ($this->fursuit)();

    actingAs($this->admin)
        ->put(route('manage.fursuits.update', $pending), $payload($pending) + ['status' => 'rejected'])
        ->assertSessionHasErrors('rejection_reason');

    actingAs($this->admin)
        ->put(route('manage.fursuits.update', $pending), $payload($pending) + [
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

    actingAs($this->admin)->put(route('manage.fursuits.update', $fursuit), [
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

test('the form validates the fields the Filament schema required', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->admin)->put(route('manage.fursuits.update', $fursuit), [])
        ->assertSessionHasErrors(['user_id', 'species_id', 'event_id', 'name', 'image', 'published', 'catch_em_all']);

    actingAs($this->admin)->put(route('manage.fursuits.update', $fursuit), [
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

    actingAs($this->admin)->delete(route('manage.fursuits.destroy', $fursuit))
        ->assertRedirect(route('manage.fursuits.index'))
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Deleted', 'body' => null]);

    expect(Fursuit::whereKey($fursuit->id)->exists())->toBeFalse()
        ->and(Fursuit::withTrashed()->whereKey($fursuit->id)->exists())->toBeTrue()
        // FursuitObserver::deleting cascades the soft delete to the fursuit's badges, so
        // none is left orphaned with a null deleted_at (audit 78, docs/bugfix-04-fix.md).
        ->and(Badge::where('fursuit_id', $fursuit->id)->exists())->toBeFalse()
        ->and(Badge::withTrashed()->where('fursuit_id', $fursuit->id)->exists())->toBeTrue();

    ($this->scoped)($this->admin, null)->get(route('manage.fursuits.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 0));
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

test('a reviewer works the queue but cannot edit or delete a record', function () {
    // The whole moderation surface is gated on `view`, which a reviewer holds, while
    // editing the row itself stays admin-only. That is the split the Filament page had.
    $fursuit = ($this->fursuit)();

    actingAs($this->reviewer);

    get(route('manage.fursuits.index'))->assertSuccessful();
    get(route('manage.fursuits.show', $fursuit))->assertSuccessful();
    post(route('manage.fursuits.claim', $fursuit))->assertRedirect();
    get(route('manage.fursuits.next', $fursuit))->assertRedirect();

    get(route('manage.fursuits.edit', $fursuit))->assertForbidden();
    put(route('manage.fursuits.update', $fursuit), [])->assertForbidden();
    delete(route('manage.fursuits.destroy', $fursuit))->assertForbidden();

    expect(Fursuit::whereKey($fursuit->id)->exists())->toBeTrue();
});

test('a user with neither flag is shut out of the module', function () {
    $fursuit = ($this->fursuit)();

    actingAs($this->outsider);

    get(route('manage.fursuits.index'))->assertForbidden();
    get(route('manage.fursuits.show', $fursuit))->assertForbidden();
    post(route('manage.fursuits.claim', $fursuit))->assertForbidden();
    post(route('manage.fursuits.approve', $fursuit))->assertForbidden();
    post(route('manage.fursuits.reject', $fursuit), ['custom_reason' => 'x'])->assertForbidden();
    post(route('manage.fursuits.notify', $fursuit), ['notification_type' => 'approved'])->assertForbidden();
});

test('no create route exists and the policy still refuses creation', function () {
    // plan 2.2, audit 38: create() returning false is not a bug to fix during a rewrite.
    expect(Gate::forUser($this->admin)->allows('create', Fursuit::class))->toBeFalse()
        ->and(app('router')->getRoutes()->getByName('manage.fursuits.create'))->toBeNull();
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

    ($this->scoped)($this->admin, $this->event->id)->get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageNav', function ($groups) {
            $item = collect($groups)
                ->flatMap(fn ($group) => $group['items'])
                ->firstWhere('label', 'Fursuits');

            return $item !== null
                && $item['url'] === route('manage.fursuits.index')
                && $item['badge'] === ['label' => '1', 'tone' => 'warn'];
        }));
});
