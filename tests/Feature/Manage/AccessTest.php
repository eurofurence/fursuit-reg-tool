<?php

/*
 * Phase 0 access contract for the Inertia panel at /admin (plan part 4.2, item 1).
 *
 * There is no baseline suite to inherit here: DbServiceMaintenancePageTest was the only
 * test in the repository that touched the admin at all, and it covered one Filament page.
 * So this file states the panel-level rules from scratch rather than porting them.
 *
 * Filament is gone (plan part 5). /admin-legacy is now a redirect kept for one release so
 * bookmarked deep links land on the new panel, and that is what is pinned below.
 *
 * The route names are still manage.*: admin.* belongs to admin.badge-pdf.* until the
 * rename phase.
 */

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    // Something has to exist for the event scope to seed from; the middleware runs on
    // every /admin request whether or not the page cares about the selection.
    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
        'order_starts_at' => now()->subDay(),
        'order_ends_at' => now()->addDays(20),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->attendee = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);
});

test('a guest is redirected to login rather than shown a manage login form', function () {
    // There is no /admin/login. The `auth` middleware pushes guests into the existing
    // Identity SSO flow, which is what the Filament panel already does.
    get(route('manage.dashboard'))
        ->assertRedirect(route('login'));
});

test('the panel is mounted at /admin and the Filament route names are gone', function () {
    expect(route('manage.dashboard', absolute: false))->toBe('/admin');
    expect(Route::has('filament.admin.pages.dashboard'))->toBeFalse();
});

test('the admin.badge-pdf routes still resolve under /admin', function () {
    // They share the /admin prefix with the panel and are registered first, so the
    // panel's own routes must not be able to swallow them.
    expect(route('admin.badge-pdf.view', ['customId' => 'EF29-1'], absolute: false))
        ->toBe('/admin/badge-pdf/EF29-1/view');

    expect(Route::getRoutes()->match(Request::create('/admin/badge-pdf/EF29-1/view', 'GET'))->getName())
        ->toBe('admin.badge-pdf.view');

    expect(Route::getRoutes()->match(Request::create('/admin', 'GET'))->getName())
        ->toBe('manage.dashboard');
});

test('the admin.badge-pdf routes refuse a signed-in attendee', function () {
    // They sat behind `auth` alone (audit landmine 60), and `custom_id` is
    // `{attendee_id}-{n}`, so the whole namespace was enumerable from any attendee
    // number: every logged-in user could pull any other attendee's badge PDF, image,
    // name, species and Catch-Em-All QR code included. Now `can:access-manage`, per
    // rebuild-plan 2.10 change 20. Asserted before the record exists, because the guard
    // has to run ahead of the lookup that would otherwise 404 and hide the hole.
    actingAs($this->attendee);

    get(route('admin.badge-pdf.view', ['customId' => 'EF29-1']))->assertForbidden();
    get(route('admin.badge-pdf.download', ['customId' => 'EF29-1']))->assertForbidden();
});

test('the admin.badge-pdf routes stay open to the panel users that link to them', function () {
    // `can:access-manage` is `is_admin || is_reviewer`, the same set the retired Filament
    // panel's `User::canAccessPanel()` admitted, so nobody who could reach these links
    // loses them. A missing badge is a 404 from the
    // controller's own `firstOrFail`, which is the guard letting the request through.
    foreach ([$this->admin, $this->reviewer] as $operator) {
        actingAs($operator);

        get(route('admin.badge-pdf.view', ['customId' => 'NOPE']))->assertNotFound();
    }
});

test('a signed-in user who is neither admin nor reviewer gets 403', function () {
    actingAs($this->attendee);

    get(route('manage.dashboard'))->assertForbidden();
});

test('an admin gets the dashboard with the expected Inertia component', function () {
    actingAs($this->admin);

    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Dashboard')
            ->where('auth.can_access_manage', true)
            ->where('auth.is_admin', true)
            ->has('manageNav')
            ->has('manageEvent')
        );
});

test('a reviewer gets the dashboard too', function () {
    actingAs($this->reviewer);

    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Dashboard')
            ->where('auth.can_access_manage', true)
            // The sidebar hides what the user cannot reach, so the flag has to be
            // honest about a reviewer not being an admin.
            ->where('auth.is_admin', false)
        );
});

test('access-manage admits both flags and nobody else', function () {
    expect(Gate::forUser($this->admin)->allows('access-manage'))->toBeTrue();
    expect(Gate::forUser($this->reviewer)->allows('access-manage'))->toBeTrue();
    expect(Gate::forUser($this->attendee)->allows('access-manage'))->toBeFalse();
});

test('manage-admin separates admin from reviewer', function () {
    // The whole point of the second gate: `access-manage` is the door, `manage-admin` is
    // the successor to DbService::canAccess() and is where admin-only actually means it.
    expect(Gate::forUser($this->admin)->allows('manage-admin'))->toBeTrue();
    expect(Gate::forUser($this->reviewer)->allows('manage-admin'))->toBeFalse();
    expect(Gate::forUser($this->attendee)->allows('manage-admin'))->toBeFalse();
});

test('access-manage still spells out the rule canAccessPanel() carried', function () {
    // Nobody may lose access at cutover, so access-manage has to be exactly the old rule:
    // `is_admin || is_reviewer`, read off the flags rather than off the deleted method.
    foreach ([$this->admin, $this->reviewer, $this->attendee] as $user) {
        expect(Gate::forUser($user)->allows('access-manage'))
            ->toBe($user->is_admin || $user->is_reviewer);
    }
});

test('the manage-only props stay off every other interface', function () {
    // getManageContent() is spread in per route. If it ever leaks, the public site starts
    // paying for the nav counts and the event list on every request.
    actingAs($this->admin);

    get(route('welcome'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('manageNav')
            ->missing('manageEvent')
        );
});

test('/admin-legacy redirects to the panel instead of 404ing', function () {
    // The Filament mount is gone. The redirect stays for one release so a bookmark still
    // lands somewhere useful. It is deliberately unguarded: /admin does the checking.
    get('/admin-legacy')->assertRedirect('/admin');
});

test('a bookmarked /admin-legacy deep link redirects too', function () {
    // The catch-all is why an old resource URL does not dead-end. It lands on the
    // dashboard rather than the matching new page, which is the accepted trade.
    get('/admin-legacy/badges')->assertRedirect('/admin');
    get('/admin-legacy/print-jobs/12/edit')->assertRedirect('/admin');
});
