<?php

/*
 * DB Service (parity checklist 23, audit 5.3).
 *
 * The successor to the four cases in tests/Feature/DbServiceMaintenancePageTest.php, which
 * the plan asks phase 9 to re-express against /admin/maintenance/db-service: admin 200,
 * reviewer 403, the nav entry hidden from a non-admin, and one preview -> apply round trip
 * asserting the converted badge. That file, its Filament page and
 * App\Services\FreeBadgeRepairService were all deleted from the repository in commit
 * 5aa2148, so this file is the only coverage of the repair path that exists.
 *
 * The load-bearing case is "preview writes nothing". The page moves money on the next
 * click, so the step in front of it has to be provably a read: the test snapshots every
 * badge row plus the counts of every table the repair touches, runs the preview twice, and
 * compares.
 *
 * Two things differ from the audit's description of the repair, both because the wallet
 * package was removed in fa0554e: there is no `deposit()` credit to assert (the service had
 * already lost the call before it was deleted; docs/wallet-removal-plan.md line 140 asks
 * for exactly that), and the section copy no longer promises a wallet transaction. Zeroing
 * the total is the correction, and the activity entry still carries what was charged.
 */

use App\Http\Controllers\Manage\DbServiceController;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag. */
const MANAGE_DB_SERVICE_TOAST = 'inertia.flash_data.toast';

beforeEach(function () {
    Storage::fake('s3');

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->nobody = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    // Two events. The repair reads the newest by starts_at, whatever the header selector
    // says, so the older one exists to prove the older one is left alone.
    $this->older = Event::factory()->create([
        'name' => 'Eurofurence 28',
        'starts_at' => now()->subYear(),
        'ends_at' => now()->subYear()->addDays(5),
    ]);

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    /** Someone holding `prepaid_badges` entitlement for an event. */
    $this->attendee = function (int $prepaid, ?Event $event = null): User {
        $user = User::factory()->create(['name' => 'Owner '.Str::random(4)]);

        EventUser::create([
            'user_id' => $user->id,
            'event_id' => ($event ?? $this->event)->id,
            'attendee_id' => 'TEST-'.$user->id,
            'prepaid_badges' => $prepaid,
            'valid_registration' => true,
        ]);

        return $user;
    };

    /** A badge charged the fee: 500 cents, unpaid, not free, a main badge. */
    $this->chargedBadge = function (User $user, array $overrides = [], ?Event $event = null): Badge {
        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => ($event ?? $this->event)->id,
            'species_id' => Species::firstOrCreate(['name' => 'Wolf'], ['type' => 'canine', 'checked' => true])->id,
            'name' => 'Fluffy '.Str::random(4),
            'image' => 'fursuits/'.Str::random(8).'.jpg',
        ]);

        return Badge::factory()->create(array_merge([
            'fursuit_id' => $fursuit->id,
            'is_free_badge' => false,
            'extra_copy_of' => null,
            'status_payment' => 'unpaid',
            'subtotal' => 420,
            'tax' => 80,
            'total' => 500,
            'paid_at' => null,
        ], $overrides));
    };
});

/** Every column the repair can write, for every badge, in id order. */
function badgeSnapshot(): array
{
    return Badge::withTrashed()
        ->orderBy('id')
        ->get(['id', 'is_free_badge', 'total', 'subtotal', 'tax', 'status_payment', 'paid_at'])
        ->map(fn (Badge $badge) => $badge->getAttributes())
        ->all();
}

// -------------------------------------------------------------------------------------
// Access. The Filament page's own gate, on all three endpoints rather than only the GET.
// -------------------------------------------------------------------------------------

test('a guest is redirected to login', function () {
    get(route('admin.maintenance.db-service'))->assertRedirect();
});

test('an attendee cannot reach the page at all', function () {
    actingAs($this->nobody)->get(route('admin.maintenance.db-service'))->assertForbidden();
});

test('a reviewer is refused, because the page is admin-only', function () {
    actingAs($this->reviewer)->get(route('admin.maintenance.db-service'))->assertForbidden();
});

test('a reviewer is refused the preview and the apply, not only the page', function () {
    actingAs($this->reviewer)->post(route('admin.maintenance.db-service.preview'))->assertForbidden();
    actingAs($this->reviewer)->post(route('admin.maintenance.db-service.apply'))->assertForbidden();
});

test('an attendee is refused the preview and the apply', function () {
    actingAs($this->nobody)->post(route('admin.maintenance.db-service.preview'))->assertForbidden();
    actingAs($this->nobody)->post(route('admin.maintenance.db-service.apply'))->assertForbidden();
});

test('a refused apply writes nothing', function () {
    $user = ($this->attendee)(1);
    ($this->chargedBadge)($user);

    $before = badgeSnapshot();

    actingAs($this->reviewer)->post(route('admin.maintenance.db-service.apply'))->assertForbidden();

    expect(badgeSnapshot())->toBe($before);
});

test('an admin reaches the page', function () {
    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/Tools/DbService'));
});

/*
 * The successor to the rail assertion: DB Service has no rail row of its own since the
 * Maintenance group was folded into the Tools index, so what has to stay true is that its
 * card is on that index.
 *
 * The reviewer half of this test is gone with the whole Tools index, which is admin-only
 * now that every card on it is (docs/admin/roles.md). Navigation::tools() still filters on
 * `manage-admin` and that gate is still what puts this card on the page; it simply has
 * nobody left to filter it out for. ReviewerScopeTest asserts the index refuses a reviewer.
 */
test('the Tools card is offered to an admin', function () {
    $labels = fn ($tools) => collect($tools)->pluck('label')->all();

    actingAs($this->admin)
        ->get(route('admin.tools.index'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'tools',
            fn ($tools) => in_array('DB Service', $labels($tools), true)
        ));
});

// -------------------------------------------------------------------------------------
// The idle page.
// -------------------------------------------------------------------------------------

test('the page opens idle, naming the active event and offering only the preview', function () {
    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Tools/DbService')
            // Event::getActiveEvent(): the newest by starts_at.
            ->where('event.name', 'Eurofurence 29')
            ->where('report', null)
            ->where('result', null)
            ->where('actions.0.name', 'preview')
            ->where('actions.0.label', 'Fix free badges')
            ->where('actions.0.icon', 'search')
            ->where('actions.0.method', 'post')
            ->count('actions', 1)
        );
});

// Checklist line 57: the page is not selector-scoped, it uses Event::getActiveEvent().
test('the page ignores the header event selection', function () {
    actingAs($this->admin)->post(route('admin.event.select'), ['event_id' => $this->older->id]);

    $user = ($this->attendee)(1, $this->older);
    ($this->chargedBadge)($user, [], $this->older);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.name', 'Eurofurence 29')
            // The older event's wrongly charged badge is not this page's business.
            ->where('report.affected_badge_count', 0)
        );
});

// -------------------------------------------------------------------------------------
// Preview. A read, and nothing else.
// -------------------------------------------------------------------------------------

test('the preview writes nothing', function () {
    $user = ($this->attendee)(2);
    ($this->chargedBadge)($user);
    ($this->chargedBadge)($user);

    $before = badgeSnapshot();
    $counts = [
        'badges' => DB::table('badges')->count(),
        'event_users' => DB::table('event_users')->count(),
        'activity_log' => DB::table('activity_log')->count(),
        'fursuits' => DB::table('fursuits')->count(),
        'users' => DB::table('users')->count(),
    ];

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.preview'))
        ->assertRedirect(route('admin.maintenance.db-service', ['review' => 1]));

    // And again through the GET that renders the review, which recomputes the report.
    actingAs($this->admin)->get(route('admin.maintenance.db-service', ['review' => 1]))->assertOk();

    expect(badgeSnapshot())->toBe($before);
    expect(DB::table('badges')->count())->toBe($counts['badges']);
    expect(DB::table('event_users')->count())->toBe($counts['event_users']);
    expect(DB::table('activity_log')->count())->toBe($counts['activity_log']);
    expect(DB::table('fursuits')->count())->toBe($counts['fursuits']);
    expect(DB::table('users')->count())->toBe($counts['users']);
});

test('an empty preview flashes Nothing to fix and still shows its zeroed cards', function () {
    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.preview'))
        ->assertRedirect(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertSessionHas(MANAGE_DB_SERVICE_TOAST, [
            'tone' => 'success',
            'title' => 'Nothing to fix',
            'body' => 'No wrongly-charged prepaid badges were found for the current event.',
        ]);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.affected_badge_count', 0)
            ->where('report.affected_user_count', 0)
            ->where('report.total_refund', '€0.00')
            ->where('report.rows', [])
            // Nothing to apply, so only Cancel is offered.
            ->where('actions.0.name', 'cancel')
            ->count('actions', 1)
        );
});

test('a preview that found something does not flash Nothing to fix', function () {
    ($this->chargedBadge)(($this->attendee)(1));

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.preview'))
        ->assertSessionMissing(MANAGE_DB_SERVICE_TOAST);
});

// The blade fell back to an em dash for a missing fursuit, species or owner and to a
// placeholder image; the page does the same, and this is the server half of it.
test('a row with no stored image reports a null image url for the page to fall back on', function () {
    $user = ($this->attendee)(1);
    $badge = ($this->chargedBadge)($user);
    $badge->fursuit->forceFill(['image' => null])->saveQuietly();

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('report.rows.0.image_url', null));
});

test('the review reports the badge, the counts and the money', function () {
    $user = ($this->attendee)(1);
    $badge = ($this->chargedBadge)($user);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.affected_badge_count', 1)
            ->where('report.affected_user_count', 1)
            ->where('report.total_refund_cents', 500)
            // Cents in, euros out, through the one server-side formatter (plan 2.10 #1).
            ->where('report.total_refund', '€5.00')
            ->where('report.rows.0.badge_id', $badge->id)
            ->where('report.rows.0.owner', $user->name)
            ->where('report.rows.0.species', 'Wolf')
            ->where('report.rows.0.badges_total', 1)
            ->where('report.rows.0.should_be_free', 1)
            ->where('report.rows.0.should_be_paid', 0)
            ->where('report.rows.0.refund', '€5.00')
            // A 15-minute signed URL, or the disk's plain URL when the fake cannot sign.
            ->where('report.rows.0.image_url', fn ($url) => is_string($url) && $url !== '')
        );
});

test('the apply button carries the confirm copy verbatim and only when there is work', function () {
    $user = ($this->attendee)(2);
    ($this->chargedBadge)($user);
    ($this->chargedBadge)($user, ['total' => 1000, 'subtotal' => 840, 'tax' => 160]);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.0.name', 'apply')
            ->where('actions.0.label', 'Confirm & apply fix')
            ->where('actions.0.icon', 'check')
            ->where('actions.0.tone', 'ok')
            ->where('actions.0.method', 'post')
            // db-service.blade.php:112, byte for byte.
            ->where(
                'actions.0.confirm.description',
                'Convert 2 badge(s) to free and refund €15.00? This cannot be undone automatically.'
            )
            ->where('actions.1.name', 'cancel')
            ->where('actions.1.label', 'Cancel')
            ->where('actions.1.icon', 'x')
            ->where('actions.1.method', 'get')
            ->count('actions', 2)
        );
});

// -------------------------------------------------------------------------------------
// Apply. The one write.
// -------------------------------------------------------------------------------------

test('apply writes exactly what the preview promised', function () {
    $user = ($this->attendee)(1);
    $badge = ($this->chargedBadge)($user);
    $untouched = ($this->chargedBadge)(($this->attendee)(0));

    $promised = null;

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(function (Assert $page) use (&$promised) {
            $promised = $page->toArray()['props']['report'];
        });

    expect(collect($promised['rows'])->pluck('badge_id')->all())->toBe([$badge->id]);

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.apply'))
        ->assertRedirect(route('admin.maintenance.db-service'));

    $badge->refresh();
    expect($badge->is_free_badge)->toBeTrue();
    expect((int) $badge->total)->toBe(0);
    expect((int) $badge->subtotal)->toBe(0);
    expect((int) $badge->tax)->toBe(0);
    expect($badge->status_payment->equals(Paid::class))->toBeTrue();
    expect($badge->paid_at)->not->toBeNull();

    // Only the promised badge moved: the one whose owner has no entitlement is as it was.
    $untouched->refresh();
    expect($untouched->is_free_badge)->toBeFalse();
    expect((int) $untouched->total)->toBe(500);

    // And the money it promised is the money it reports having moved.
    expect($promised['total_refund_cents'])->toBe(500);
});

test('apply flashes the fix applied notification and reports the result', function () {
    $user = ($this->attendee)(1);
    ($this->chargedBadge)($user);

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.apply'))
        ->assertSessionHas(MANAGE_DB_SERVICE_TOAST, [
            'tone' => 'success',
            'title' => 'Fix applied',
            'body' => 'Converted 1 badge(s) for 1 user(s) to free.',
        ]);

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.apply'))
        ->assertRedirect(route('admin.maintenance.db-service'));
});

test('the result panel shows the counters and offers Run again', function () {
    $user = ($this->attendee)(1);
    ($this->chargedBadge)($user);

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.apply'))
        ->assertRedirect(route('admin.maintenance.db-service'));

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('result.success', true)
            ->where('result.fixed_badge_count', 1)
            ->where('result.fixed_user_count', 1)
            ->where('result.total_refunded_cents', 500)
            ->where('result.total_refunded', '€5.00')
            ->where('result.error', null)
            ->where('actions.0.name', 'run-again')
            ->where('actions.0.label', 'Run again')
            ->where('actions.0.icon', 'refresh-cw')
            ->where('actions.0.method', 'get')
            ->count('actions', 1)
        );

    // Run again is a link back to the bare page, so the result is gone on the next load
    // and nothing was written to clear it.
    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service'))
        ->assertInertia(fn (Assert $page) => $page->where('result', null)->where('report', null));
});

test('apply logs the correction against the badge with the causer and the old money', function () {
    $user = ($this->attendee)(1);
    $badge = ($this->chargedBadge)($user);

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    $entry = DB::table('activity_log')
        ->where('subject_id', $badge->id)
        ->where('description', DbServiceController::ACTIVITY_DESCRIPTION)
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->causer_id)->toBe($this->admin->id);

    $properties = json_decode($entry->properties, true);
    expect($properties['reason'])->toBe('free_badge_fix');
    expect($properties['event_id'])->toBe($this->event->id);
    expect($properties['prepaid_badges'])->toBe(1);
    expect($properties['old_total'])->toBe(500);
    expect($properties['old_subtotal'])->toBe(420);
    expect($properties['old_tax'])->toBe(80);
    expect($properties['new_total'])->toBe(0);
    expect($properties['refunded_cents'])->toBe(500);
});

// -------------------------------------------------------------------------------------
// The prepaid rules, which are the part of this that is easy to get wrong (CLAUDE.md,
// docs/bugfix-03-fix.md).
// -------------------------------------------------------------------------------------

test('the full entitlement is honoured, with no minus one', function () {
    $user = ($this->attendee)(2);
    $first = ($this->chargedBadge)($user);
    $second = ($this->chargedBadge)($user);

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    expect($first->refresh()->is_free_badge)->toBeTrue();
    expect($second->refresh()->is_free_badge)->toBeTrue();
});

test('spare copies are never converted and never consume the allowance', function () {
    $user = ($this->attendee)(1);
    $main = ($this->chargedBadge)($user);
    $copy = ($this->chargedBadge)($user, ['extra_copy_of' => $main->id]);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.affected_badge_count', 1)
            // badges_total counts the copy; should_be_free does not.
            ->where('report.rows.0.badges_total', 2)
            ->where('report.rows.0.should_be_free', 1)
            ->where('report.rows.0.should_be_paid', 1)
        );

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    expect($main->refresh()->is_free_badge)->toBeTrue();
    expect($copy->refresh()->is_free_badge)->toBeFalse();
    expect((int) $copy->refresh()->total)->toBe(500);
});

test('a badge already free consumes the allowance, so a second run is a no-op', function () {
    $user = ($this->attendee)(1);
    $free = ($this->chargedBadge)($user, ['is_free_badge' => true, 'total' => 0, 'subtotal' => 0, 'tax' => 0]);
    $paid = ($this->chargedBadge)($user);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('report.affected_badge_count', 0));

    $before = badgeSnapshot();

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    expect(badgeSnapshot())->toBe($before);
    expect($free->refresh()->is_free_badge)->toBeTrue();
    expect($paid->refresh()->is_free_badge)->toBeFalse();
});

// Plan 2.10 #75. The deleted service selected on `is_free_badge` alone, so a badge whose
// fee had actually been taken was zeroed as well. With the wallet gone nothing hands that
// money back, so the confirm dialog promised a refund the write could not make, `paid_at`
// was overwritten with now() and the matching checkout_items row still recorded the amount.
test('a badge that has already been paid is never converted and never priced as a refund', function () {
    $user = ($this->attendee)(1);
    $paidAt = now()->subDay()->startOfSecond();
    $badge = ($this->chargedBadge)($user, ['status_payment' => Paid::$name, 'paid_at' => $paidAt]);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.affected_badge_count', 0)
            ->where('report.affected_user_count', 0)
            // The money the confirm dialog would have promised.
            ->where('report.total_refund_cents', 0)
        );

    $before = badgeSnapshot();

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.apply'))
        ->assertSessionHas(MANAGE_DB_SERVICE_TOAST.'.body', 'Converted 0 badge(s) for 0 user(s) to free.');

    expect(badgeSnapshot())->toBe($before);

    $fresh = $badge->refresh();

    expect((int) $fresh->total)->toBe(500)
        ->and((int) $fresh->subtotal)->toBe(420)
        ->and((int) $fresh->tax)->toBe(80)
        ->and($fresh->is_free_badge)->toBeFalse()
        // The payment timestamp is not recoverable from the activity properties, so it must
        // not be overwritten in the first place.
        ->and((string) $fresh->paid_at)->toBe($paidAt->toDateTimeString())
        ->and(DB::table('activity_log')->where('description', DbServiceController::ACTIVITY_DESCRIPTION)->count())->toBe(0);
});

test('an unpaid badge is still converted when the same owner also holds a paid one', function () {
    // The entitlement is honoured where honouring it costs nothing to reverse: the paid
    // badge is left alone and the one still owing its fee becomes the free one.
    $user = ($this->attendee)(1);
    $paid = ($this->chargedBadge)($user, ['status_payment' => Paid::$name, 'paid_at' => now()->subDay()]);
    $unpaid = ($this->chargedBadge)($user);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service', ['review' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.affected_badge_count', 1)
            ->where('report.rows.0.badge_id', $unpaid->id)
        );

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    expect($unpaid->refresh()->is_free_badge)->toBeTrue()
        ->and((int) $unpaid->refresh()->total)->toBe(0)
        ->and($paid->refresh()->is_free_badge)->toBeFalse()
        ->and((int) $paid->refresh()->total)->toBe(500);
});

test('the lowest badge id is converted first when the allowance is short', function () {
    $user = ($this->attendee)(1);
    $first = ($this->chargedBadge)($user);
    $second = ($this->chargedBadge)($user);

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    expect($first->refresh()->is_free_badge)->toBeTrue();
    expect($second->refresh()->is_free_badge)->toBeFalse();
});

test('running the repair twice does not convert anything the second time', function () {
    $user = ($this->attendee)(1);
    ($this->chargedBadge)($user);

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    $after = badgeSnapshot();

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.apply'))
        ->assertSessionHas(MANAGE_DB_SERVICE_TOAST, [
            'tone' => 'success',
            'title' => 'Fix applied',
            'body' => 'Converted 0 badge(s) for 0 user(s) to free.',
        ]);

    expect(badgeSnapshot())->toBe($after);
});

test('a badge from another event is left alone', function () {
    $user = ($this->attendee)(1, $this->older);
    $badge = ($this->chargedBadge)($user, [], $this->older);

    actingAs($this->admin)->post(route('admin.maintenance.db-service.apply'));

    expect($badge->refresh()->is_free_badge)->toBeFalse();
    expect((int) $badge->refresh()->total)->toBe(500);
});

// -------------------------------------------------------------------------------------
// Failure.
// -------------------------------------------------------------------------------------

test('with no event at all the apply fails with No active event', function () {
    Badge::withTrashed()->forceDelete();
    Fursuit::withTrashed()->forceDelete();
    EventUser::query()->delete();
    Event::query()->delete();

    actingAs($this->admin)
        ->post(route('admin.maintenance.db-service.apply'))
        ->assertSessionHas(MANAGE_DB_SERVICE_TOAST, [
            'tone' => 'danger',
            'title' => 'Fix failed',
            'body' => 'No active event.',
        ]);

    actingAs($this->admin)
        ->get(route('admin.maintenance.db-service'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('event', null)
            ->where('result.success', false)
            ->where('result.error', 'No active event.')
            ->where('result.fixed_badge_count', 0)
            ->where('result.fixed_user_count', 0)
        );
});
