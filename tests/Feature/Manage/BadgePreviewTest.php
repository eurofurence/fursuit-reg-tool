<?php

/*
 * Badge Preview (parity checklist 22, audit 5.2).
 *
 * The module shipped its controller without routes, a page or tests; this file is the
 * missing third of it, transcribed from the checklist.
 *
 * Four things beyond plain parity, all of them named in the plan:
 *
 *  - the loaded badge is URL state, not component state, so the details panel survives a
 *    reload and the PDF buttons are real GET links (plan 2.10 #34, audit 49);
 *  - the badge class the panel reports is EF30_Badge, which is what BadgePdfController
 *    actually renders, rather than the blade's EF28_Badge (audit 48);
 *  - every detail is read null-safely, because the blade walked ->species->name,
 *    ->user->name and ->event->name through relations that soft-delete (audit 113);
 *  - the two PDF routes sit behind can:access-manage, unlike admin.badge-pdf.* which is
 *    behind auth alone (audit landmine 60).
 */

use App\Http\Controllers\Manage\BadgePreviewController;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag. */
const MANAGE_PREVIEW_TOAST = 'inertia.flash_data.toast';

beforeEach(function () {
    Storage::fake('s3');

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->nobody = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->event = Event::factory()->create(['name' => 'Eurofurence 29', 'badge_class' => 'EF29_Badge']);

    $this->badge = function (array $overrides = []) {
        $owner = User::factory()->create(['name' => 'Owner One']);
        $fursuit = Fursuit::factory()->create([
            'name' => 'Fluffy',
            'user_id' => $owner->id,
            'event_id' => $this->event->id,
            'species_id' => Species::factory()->create(['name' => 'Wolf'])->id,
        ]);

        return Badge::factory()->create(array_merge(['fursuit_id' => $fursuit->id, 'custom_id' => 'ABC123'], $overrides));
    };
});

test('a guest is redirected to login', function () {
    get(route('admin.tools.badge-preview'))->assertRedirect();
});

test('an attendee cannot reach the tool at all', function () {
    actingAs($this->nobody)->get(route('admin.tools.badge-preview'))->assertForbidden();
});

/*
 * Reverses checklist line 83, which kept the page open to reviewers. The tool renders any
 * attendee's card from a custom id typed into a box: it is a lookup over the whole badge
 * table rather than a review surface, and reviewers were narrowed to Dashboard, Badges and
 * Fursuits. Guarded twice, on the route group and in each of the four methods, so both
 * halves are asserted here. See docs/admin/roles.md.
 */
test('a reviewer is refused the page and both PDF routes', function () {
    // The badge exists, so a 403 here is the guard rather than the controller's own 404.
    ($this->badge)();

    actingAs($this->reviewer)->get(route('admin.tools.badge-preview'))->assertForbidden();
    actingAs($this->reviewer)->post(route('admin.tools.badge-preview.lookup'), ['custom_id' => 'ABC123'])->assertForbidden();
    actingAs($this->reviewer)->get(route('admin.tools.badge-preview.pdf.view', ['customId' => 'ABC123']))->assertForbidden();
    actingAs($this->reviewer)->get(route('admin.tools.badge-preview.pdf.download', ['customId' => 'ABC123']))->assertForbidden();
});

test('the page opens empty, with no badge and no actions', function () {
    actingAs($this->admin)
        ->get(route('admin.tools.badge-preview'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Tools/BadgePreview')
            ->where('customId', null)
            ->where('badge', null)
            ->where('actions', [])
        );
});

test('the lookup is required and capped at 255', function () {
    actingAs($this->admin);

    post(route('admin.tools.badge-preview.lookup'), [])->assertSessionHasErrors('custom_id');
    post(route('admin.tools.badge-preview.lookup'), ['custom_id' => str_repeat('a', 256)])
        ->assertSessionHasErrors('custom_id');
});

test('a found badge flashes the Filament copy and redirects to its own url', function () {
    ($this->badge)();

    actingAs($this->admin)
        ->post(route('admin.tools.badge-preview.lookup'), ['custom_id' => 'ABC123'])
        ->assertRedirect(route('admin.tools.badge-preview', ['custom_id' => 'ABC123']))
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.title', 'Badge loaded')
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.body', 'Badge found for: Fluffy');
});

test('a missing badge flashes the danger copy and still carries the typed id back', function () {
    actingAs($this->admin)
        ->post(route('admin.tools.badge-preview.lookup'), ['custom_id' => 'NOPE'])
        ->assertRedirect(route('admin.tools.badge-preview', ['custom_id' => 'NOPE']))
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.tone', 'danger')
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.title', 'Badge not found')
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.body', 'No badge found with custom ID: NOPE');
});

test('the details panel carries the six rows the blade showed', function () {
    ($this->badge)();

    actingAs($this->admin)
        ->get(route('admin.tools.badge-preview', ['custom_id' => 'ABC123']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customId', 'ABC123')
            ->where('badge.custom_id', 'ABC123')
            ->where('badge.fursuit_name', 'Fluffy')
            ->where('badge.species', 'Wolf')
            ->where('badge.owner', 'Owner One')
            ->where('badge.event', 'Eurofurence 29')
            ->where('badge.badge_class', 'EF29_Badge')
        );
});

// Audit 48: the blade said EF28_Badge and handed you an EF30 PDF.
test('the badge class falls back to EF30_Badge, which is what the renderer uses', function () {
    $this->event->update(['badge_class' => null]);
    ($this->badge)();

    expect(BadgePreviewController::DEFAULT_BADGE_CLASS)->toBe('EF30_Badge');

    actingAs($this->admin)
        ->get(route('admin.tools.badge-preview', ['custom_id' => 'ABC123']))
        ->assertInertia(fn (Assert $page) => $page->where('badge.badge_class', 'EF30_Badge'));
});

// Audit 113: this row took the whole page down.
//
// The fursuit is soft-deleted with a direct write rather than ->delete(), because the
// model cascades to its badges and a badge that is itself deleted is simply not found -
// the interesting record is the one that outlives its fursuit, which is what a restored
// badge or a partially cascaded row looks like.
test('the panel survives a soft-deleted fursuit rather than throwing', function () {
    $badge = ($this->badge)();
    DB::table('fursuits')->where('id', $badge->fursuit_id)->update(['deleted_at' => now()]);

    actingAs($this->admin)
        ->get(route('admin.tools.badge-preview', ['custom_id' => 'ABC123']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('badge.custom_id', 'ABC123')
            ->where('badge.fursuit_name', null)
            ->where('badge.species', null)
            ->where('badge.owner', null)
            ->where('badge.event', null)
            ->where('badge.badge_class', 'EF30_Badge')
        );
});

test('the two PDF buttons are GET links and only the view one opens a new tab', function () {
    ($this->badge)();

    actingAs($this->admin)
        ->get(route('admin.tools.badge-preview', ['custom_id' => 'ABC123']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions.0.name', 'view-pdf')
            ->where('actions.0.label', 'View PDF in Browser')
            ->where('actions.0.method', 'get')
            ->where('actions.0.newTab', true)
            ->where('actions.0.url', route('admin.tools.badge-preview.pdf.view', ['customId' => 'ABC123']))
            ->where('actions.1.name', 'download-pdf')
            ->where('actions.1.label', 'Download PDF')
            ->where('actions.1.method', 'get')
            // The blade put `target="_blank"` on the view button alone, and the download
            // is served `Content-Disposition: attachment`, so it needs no tab of its own.
            ->where('actions.1.newTab', false)
            ->where('actions.1.tone', 'ok')
            ->where('actions.1.url', route('admin.tools.badge-preview.pdf.download', ['customId' => 'ABC123']))
        );
});

test('an unknown id on either PDF route warns rather than rendering', function () {
    actingAs($this->admin);

    get(route('admin.tools.badge-preview.pdf.view', ['customId' => 'NOPE']))
        ->assertRedirect(route('admin.tools.badge-preview', ['custom_id' => 'NOPE']))
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.title', 'No badge loaded')
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.body', 'Please load a badge first');

    get(route('admin.tools.badge-preview.pdf.download', ['customId' => 'NOPE']))
        ->assertRedirect(route('admin.tools.badge-preview', ['custom_id' => 'NOPE']))
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.title', 'No badge loaded')
        ->assertSessionHas(MANAGE_PREVIEW_TOAST.'.body', 'Please load a badge first');
});

// Audit landmine 60: admin.badge-pdf.* is behind `auth` alone.
test('the PDF routes are gated, unlike the routes they replace', function () {
    ($this->badge)();

    actingAs($this->nobody)
        ->get(route('admin.tools.badge-preview.pdf.view', ['customId' => 'ABC123']))
        ->assertForbidden();
});

// The rail carries one "Tools" entry now, not a row per tool, so the card on the Tools
// index is what makes this page reachable.
test('the tool is reachable from the Tools index', function () {
    $tools = actingAs($this->admin)
        ->get(route('admin.tools.index'))
        ->viewData('page')['props']['tools'];

    expect(collect($tools)->pluck('label'))->toContain('Badge Preview')
        ->and(collect($tools)->firstWhere('label', 'Badge Preview')['url'])
        ->toBe(route('admin.tools.badge-preview'));
});
