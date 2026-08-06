<?php

/*
 * Phase 0 access contract for the /manage panel (plan part 4.2, item 1).
 *
 * There is no baseline suite to inherit here: DbServiceMaintenancePageTest is the only
 * test in the repository that touches the admin at all, and it covers one Filament page.
 * So this file states the panel-level rules from scratch rather than porting them, and it
 * also pins /admin as still working, because Filament has to keep serving the whole
 * migration and nothing in phase 0 is allowed to disturb it.
 */

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    // Something has to exist for the event scope to seed from; the middleware runs on
    // every /manage request whether or not the page cares about the selection.
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
    // There is no /manage/login. The `auth` middleware pushes guests into the existing
    // Identity SSO flow, which is what the Filament panel already does.
    get(route('manage.dashboard'))
        ->assertRedirect(route('login'));
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
            ->toBe($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')));
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

test('/admin is untouched and still reachable for an admin', function () {
    // Filament serves the real admin until phase 10. Phase 0 must not have moved it.
    actingAs($this->admin);

    get(route('filament.admin.pages.dashboard'))->assertSuccessful();
});

test('/admin keeps its own guard: reviewer in, attendee out, guest redirected', function () {
    actingAs($this->reviewer);
    get(route('filament.admin.pages.dashboard'))->assertSuccessful();

    actingAs($this->attendee);
    get(route('filament.admin.pages.dashboard'))->assertForbidden();

    auth()->logout();
    get(route('filament.admin.pages.dashboard'))->assertRedirect();
});
