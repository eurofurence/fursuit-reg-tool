<?php

use App\Http\Controllers\Admin\BadgePdfController;
use App\Http\Controllers\Manage\BadgePreviewController;
use App\Http\Controllers\Manage\CatchEmAllCacheController;
use App\Http\Controllers\Manage\DbServiceController;
use App\Http\Controllers\Manage\PdfGeneratorController;
use App\Http\Controllers\Manage\ToolsController;
use Illuminate\Support\Facades\Route;

/*
 * Tools. Read-only pages over data other modules own.
 *
 * Badge Preview replaces App\the old panel\Pages\BadgePreview. The Livewire page kept the
 * loaded badge in component state; here the state is the URL, so the lookup POSTs and
 * redirects to `?custom_id=…` and the two PDF buttons are plain GET links a browser can
 * genuinely open in a new tab.
 *
 * The two PDF routes are the successors of `admin.badge-pdf.view` and
 * `admin.badge-pdf.download`, which sat behind `auth` alone, so any signed-in attendee
 * could pull any badge PDF by custom id. Both survive as separate
 * routes because view and download are two distinct actions and collapsing them into one
 * endpoint loses the download.
 *
 * `{customId}` is the last segment of each path and every other segment is a literal, so
 * nothing here shadows anything. The id is a free-form string, not an integer, so it
 * carries no numeric constraint; the controller applies the form's own 255-char limit.
 *
 * `can:manage-admin`, not the group's `can:access-manage`, and this reverses parity
 * checklist line 83. Badge Preview renders any attendee's card from a custom id typed into
 * a box: it is a lookup over the whole badge table, not a review surface, and a reviewer
 * has no reason to pull one. Same for the PDF Generator's badge lists and box labels
 * below. See docs/admin/roles.md.
 */
Route::prefix('tools')->name('tools.')->middleware('can:manage-admin')->group(function () {
    /*
     * The index the rail links to: one card per tool, from Navigation::tools(). It replaces
     * the Tools and Maintenance rail groups, which is why DB Service - a different prefix,
     * a different guard - is reachable from here.
     */
    Route::get('/', [ToolsController::class, 'index'])->name('index');

    Route::get('badge-preview', [BadgePreviewController::class, 'index'])->name('badge-preview');
    Route::post('badge-preview', [BadgePreviewController::class, 'lookup'])->name('badge-preview.lookup');
    Route::get('badge-preview/{customId}/pdf', [BadgePreviewController::class, 'viewPdf'])->name('badge-preview.pdf.view');
    Route::get('badge-preview/{customId}/pdf/download', [BadgePreviewController::class, 'downloadPdf'])->name('badge-preview.pdf.download');
});

/*
 * The badge PDF routes the preview above supersedes, kept alive because their URLs are
 * the ones staff have bookmarked and hand each other.
 *
 * They used to be a second `/admin` group in routes/web.php with `admin.badge-pdf.*`
 * written out by hand, registered before the panel so the panel could not swallow them.
 * That is what held the `admin.` name prefix hostage. Registering them inside the panel
 * group resolves it without renaming anything an operator can see: the group contributes
 * the `admin.` prefix, so `badge-pdf.view` here is still `admin.badge-pdf.view`, and the
 * group's `/admin` prefix keeps the URLs byte for byte. One group owns /admin now.
 *
 * No shadowing to arrange: `badge-pdf` is a literal first segment no other module claims,
 * so registration order against the rest of the panel does not matter.
 *
 * The guard is `can:manage-admin`, tightened from the `can:access-manage` the old group
 * carried, so these two answer exactly what Badge Preview above answers: a badge PDF by
 * custom id is an admin lookup. `auth` and ManageEventScope come from the panel group;
 * the latter only seeds the event selection and has nothing to say about a PDF.
 */
Route::middleware('can:manage-admin')->group(function () {
    Route::get('badge-pdf/{customId}/view', [BadgePdfController::class, 'view'])->name('badge-pdf.view');
    Route::get('badge-pdf/{customId}/download', [BadgePdfController::class, 'download'])->name('badge-pdf.download');
});

/*
 * Maintenance. One page, one repair, and the only endpoints in the panel that
 * exist to change data rather than to manage a record.
 *
 * DB Service replaces App\the old panel\Pages\DbService. It shares this file with Tools because
 * both are pages rather than resources and neither is worth a file of its own; the paths
 * are a separate prefix group, so nothing about `tools.` applies to them.
 *
 * Three routes. The GET
 * renders idle, review (`?review=1`) or result; `preview` reads and redirects into the
 * review; `apply` is the single write. Cancel and "Run again" are plain links back to the
 * GET, because clearing the screen must not be a request that can change anything.
 *
 * `can:access-manage` on the group is not the guard here. The panel admits reviewers and
 * this page moves money, so `manage-admin` guards it twice over: `can:manage-admin` on the
 * group below stops the request before it reaches a controller, and every one of the three
 * methods also calls `Gate::authorize('manage-admin')` itself, the successor to
 * `DbService::canAccess()`. Neither is redundant. The middleware is what a route audit can
 * see without reading three method bodies; the in-controller authorize is what keeps the
 * guard attached to `apply()` if these routes are ever regrouped or re-registered
 * elsewhere. The one write in the panel is worth both.
 */
Route::prefix('maintenance')->name('maintenance.')->middleware('can:manage-admin')->group(function () {
    Route::get('db-service', [DbServiceController::class, 'index'])->name('db-service');
    Route::post('db-service/preview', [DbServiceController::class, 'preview'])->name('db-service.preview');
    Route::post('db-service/apply', [DbServiceController::class, 'apply'])->name('db-service.apply');
});

/*
 * PDF Generator, the other read-only tool. Its own group rather than a line in
 * the one above, so the two tools stay separately readable.
 *
 * Three routes: the form, and one download each. Both downloads are GET, because a PDF is
 * a read and a GET is the one shape a browser answers by saving a file while leaving the
 * page it was opened from alone. Nothing here writes; the form state travels in the query
 * string and PdfGeneratorRequest is the only thing that inspects it.
 *
 * `pdf` rather than `pdf-generator` as the name, because App\Support\Manage\Navigation
 * already links `admin.tools.pdf` and the rail is the caller.
 *
 * `can:manage-admin`, same as Badge Preview above and for the same reason: both downloads
 * enumerate the badge table for the print run, which is admin work.
 */
Route::prefix('tools')->name('tools.')->middleware('can:manage-admin')->group(function () {
    Route::get('pdf-generator', [PdfGeneratorController::class, 'index'])->name('pdf');
    Route::get('pdf-generator/badge-list', [PdfGeneratorController::class, 'badgeList'])->name('pdf.badge-list');
    Route::get('pdf-generator/box-labels', [PdfGeneratorController::class, 'boxLabels'])->name('pdf.box-labels');
    Route::get('catch-em-all-cache', [CatchEmAllCacheController::class, 'index'])->name('catch-em-all-cache');
    Route::post('catch-em-all-cache/{key}/forget', [CatchEmAllCacheController::class, 'forget'])->name('catch-em-all-cache.forget');
    Route::post('catch-em-all-cache/forget-all', [CatchEmAllCacheController::class, 'forgetAll'])->name('catch-em-all-cache.forget-all');
});

/*
 * The booth split used to live here as `tools.pickup-booths`. It moved to Settings >
 * On-Site Desk (routes/manage/settings.php) because it configures the convention rather
 * than running something over it, which is the line Tools and Settings are split on. The
 * storage did not move: it is still `events.pickup_booths`.
 *
 * The old URL stays as a redirect, because it is the one panel page an operator is likely
 * to have bookmarked: it was the only way to retune the ranges mid-convention, which is
 * exactly when nobody has time to go hunting through the rail for where it went.
 *
 * Not `tools.pickup-booths`: App\Support\Manage\Navigation drops a rail item whose route
 * does not exist, and reusing the old name would put "Pickup Booths" back in Tools
 * pointing at a redirect. It is named all the same, because a route registered inside the
 * `admin.` name group with no name of its own is not unnamed - it inherits the group's
 * prefix and is registered as `admin.`, so it squats a name a second unnamed route would
 * then collide with, and `route:cache` refuses a duplicate name at deploy time.
 *
 * The target is resolved per request rather than written out, so the two cannot drift.
 */
Route::get('tools/pickup-booths', fn () => redirect()->route('admin.settings.on-site-desk'))
    ->middleware('can:manage-admin')
    ->name('tools.pickup-booths-moved');
