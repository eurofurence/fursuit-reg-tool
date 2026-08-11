<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * What a reviewer may reach, enumerated from the route table rather than asserted screen
 * by screen.
 *
 * `access-manage` is the panel door and it admits two roles, so every module that does not
 * say otherwise is open to both. That default is the wrong way round for a reviewer, whose
 * whole job is the fursuit queue: at cutover it handed them checkouts, the live print run,
 * the settings panes, both PDF tools, an S3 upload endpoint and the ability to move a badge
 * between fulfillment states. See docs/admin/roles.md.
 *
 * The allowlist below is therefore the specification, not a summary of one. A new module
 * lands with no `can:manage-admin` on its group, is not named here, and this test fails -
 * which is the point. Adding the middleware or adding the name are both one line, and the
 * choice between them has to be made deliberately.
 */
beforeEach(function () {
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
});

/**
 * Route names a reviewer is allowed to reach. Three screens, plus the plumbing those three
 * need: the event scope selector in the header, and the table column preferences the
 * badge and fursuit lists write.
 *
 * `admin.badges.edit` is here and `admin.badges.update` is not. There is no separate badge
 * show page, so the edit route is the only badge detail the panel has; it authorizes
 * `view` and renders read-only when `update` is denied.
 */
$allowed = [
    'admin.dashboard',
    'admin.event.select',
    'admin.tables.columns',

    'admin.badges.index',
    'admin.badges.edit',

    'admin.fursuits.index',
    'admin.fursuits.show',
    'admin.fursuits.next',
    'admin.fursuits.review',
    'admin.fursuits.review.show',
    'admin.fursuits.review.undo',
    'admin.fursuits.approve',
    'admin.fursuits.approve-rejected',
    'admin.fursuits.reject',
    'admin.fursuits.block-publication',
    'admin.fursuits.unblock-publication',
    'admin.fursuits.notify',

    /*
     * The second review queue. Reviewing what an attendee wrote about themselves is the
     * same job as reviewing their fursuit photo, so the module carries no
     * `can:manage-admin` and every one of its routes is a verdict or the way to the next
     * one. Editing or deleting a profile row is not among them: no such route exists, and
     * UserProfilePolicy answers `update` and `delete` with is_admin.
     */
    'admin.profiles.index',
    'admin.profiles.show',
    'admin.profiles.review',
    'admin.profiles.approve',
    'admin.profiles.reject',
    'admin.profiles.reopen',
    'admin.profiles.unclaim',
    'admin.profiles.next',
];

/**
 * Every admin.* route, as [name => middleware], with the parameterless ones the other
 * tests below need.
 *
 * @return array<string, array<int, string>>
 */
function adminRouteMiddleware(): array
{
    $out = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'admin.')) {
            continue;
        }

        $out[$name] = $route->gatherMiddleware();
    }

    return $out;
}

test('every admin route is either reviewer-allowed or guarded by manage-admin', function () use ($allowed) {
    $unguarded = [];

    foreach (adminRouteMiddleware() as $name => $middleware) {
        if (in_array($name, $allowed, true)) {
            continue;
        }

        $guarded = collect($middleware)->contains(
            fn ($item) => is_string($item) && str_contains($item, 'manage-admin')
        );

        if (! $guarded) {
            $unguarded[] = $name;
        }
    }

    expect($unguarded)->toBe([],
        'These admin routes are reachable by a reviewer and are not on the allowlist in '
        .'tests/Feature/Manage/ReviewerScopeTest.php. Either add `can:manage-admin` to the '
        .'route group, or add the name to $allowed and say why in docs/admin/roles.md: '
        .implode(', ', $unguarded));
});

test('the allowlist names routes that actually exist', function () use ($allowed) {
    $names = array_keys(adminRouteMiddleware());

    expect(array_values(array_diff($allowed, $names)))->toBe([]);
});

test('a reviewer is refused the surfaces that were open at cutover', function () {
    $this->actingAs($this->reviewer);

    // One per subject that lost the reviewer, GET only, so the assertion is about the
    // guard rather than about any request body.
    $refused = [
        'admin.checkouts.index',
        'admin.print-batches.index',
        'admin.print-jobs.index',
        'admin.printers.index',
        'admin.machines.index',
        'admin.staff.index',
        'admin.sumup-readers.index',
        'admin.tse-clients.index',
        'admin.special-codes.index',
        'admin.settings.general',
        'admin.settings.on-site-desk',
        'admin.settings.review-reasons',
        'admin.settings.events.index',
        'admin.settings.users.index',
        'admin.tools.index',
        'admin.tools.badge-preview',
        'admin.tools.pdf',
        'admin.maintenance.db-service',
    ];

    foreach ($refused as $name) {
        $this->get(route($name))->assertForbidden();
    }
});

test('an admin still reaches every one of them', function () {
    $this->actingAs($this->admin);

    foreach ([
        'admin.checkouts.index',
        'admin.print-batches.index',
        'admin.print-jobs.index',
        'admin.printers.index',
        'admin.machines.index',
        'admin.staff.index',
        'admin.sumup-readers.index',
        'admin.tse-clients.index',
        'admin.special-codes.index',
        'admin.settings.general',
        'admin.settings.on-site-desk',
        'admin.settings.review-reasons',
        'admin.settings.events.index',
        'admin.settings.users.index',
        'admin.tools.index',
        'admin.tools.badge-preview',
        'admin.tools.pdf',
        'admin.maintenance.db-service',
    ] as $name) {
        $this->get(route($name))->assertOk();
    }
});

test('a reviewer keeps the three screens the role exists for', function () {
    $this->actingAs($this->reviewer);

    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('admin.badges.index'))->assertOk();
    $this->get(route('admin.fursuits.index'))->assertOk();
});

test('the badge PDF routes no longer answer a reviewer', function () {
    // The reason these exist at all is that they once sat behind `auth` alone and any
    // signed-in attendee could pull any badge PDF by custom id. The
    // fix stopped at the panel door; this takes it to the admin one.
    $this->actingAs($this->reviewer);

    $this->get(route('admin.badge-pdf.view', ['customId' => 'AB-1']))->assertForbidden();
    $this->get(route('admin.badge-pdf.download', ['customId' => 'AB-1']))->assertForbidden();
});

test('the upload endpoint is closed to a reviewer', function () {
    $this->actingAs($this->reviewer);

    $this->post(route('admin.uploads.store'), ['purpose' => 'fursuit_image'])
        ->assertForbidden();
});

test('the rail a reviewer is served holds Dashboard, Badges, Fursuits and Profiles and nothing else', function () {
    $this->actingAs($this->reviewer);

    $groups = $this->get(route('admin.dashboard'))
        ->viewData('page')['props']['manageNav'];

    $labels = collect($groups)
        ->flatMap(fn (array $group) => array_column($group['items'], 'label'))
        ->all();

    expect($labels)->toBe(['Dashboard', 'Badges', 'Fursuits', 'Profiles']);
});
