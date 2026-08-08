<?php

/*
 * Phase 0 access contract for the Inertia panel at /admin (plan part 4.2, item 1).
 *
 * There is no baseline suite to inherit here: DbServiceMaintenancePageTest is the only
 * test in the repository that touches the admin at all, and it covers one Filament page.
 * So this file states the panel-level rules from scratch rather than porting them, and it
 * also pins /admin-legacy as still working, because Filament has to keep serving the whole
 * migration and nothing in phase 0 is allowed to disturb it.
 *
 * The route names are still manage.*: admin.* belongs to admin.badge-pdf.* until part 5.
 */

use App\Models\Event;
use App\Models\User;
use Filament\Facades\Filament;
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

test('the panel is mounted at /admin and Filament has moved to /admin-legacy', function () {
    expect(route('manage.dashboard', absolute: false))->toBe('/admin');
    expect(route('filament.admin.pages.dashboard', absolute: false))->toBe('/admin-legacy');
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
    // `can:access-manage` is `is_admin || is_reviewer`, the same set
    // `User::canAccessPanel()` lets into the Filament panel that renders these links, so
    // nobody who can reach them today loses them. A missing badge is a 404 from the
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

test('the two gates agree with the panel access User::canAccessPanel() grants today', function () {
    // Nobody may lose access at cutover, so access-manage has to be exactly the old rule.
    foreach ([$this->admin, $this->reviewer, $this->attendee] as $user) {
        expect(Gate::forUser($user)->allows('access-manage'))
            ->toBe($user->canAccessPanel(Filament::getPanel('admin')));
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

test('/admin-legacy is untouched and still reachable for an admin', function () {
    // Filament serves the real admin until phase 10. Only its mount path moved.
    actingAs($this->admin);

    get(route('filament.admin.pages.dashboard'))->assertSuccessful();
});

test('/admin-legacy keeps its own guard: reviewer in, attendee out, guest redirected', function () {
    actingAs($this->reviewer);
    get(route('filament.admin.pages.dashboard'))->assertSuccessful();

    actingAs($this->attendee);
    get(route('filament.admin.pages.dashboard'))->assertForbidden();

    auth()->logout();
    get(route('filament.admin.pages.dashboard'))->assertRedirect();
});
