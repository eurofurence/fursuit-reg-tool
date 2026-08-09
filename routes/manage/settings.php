<?php

use App\Http\Controllers\Manage\OnSiteDeskController;
use App\Http\Controllers\Manage\ReviewReasonController;
use App\Http\Controllers\Manage\SettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Settings. Configuration only: one real URL per pane.
 *
 * The panes are routes rather than client-side tab state, so every one of them is
 * linkable, back-button-able and testable, and so the second vertical menu inside the page
 * body highlights from the URL the same way the primary rail does. `/` renders General
 * rather than redirecting to it: a redirect would put a hop between the rail item and the
 * first pane for no gain, and General is the landing pane, not an alias. General configures
 * nothing itself - it is the placeholder that asks for a pane from the submenu - because
 * every general field an event has is a column the Events form already owns.
 *
 * Only `admin.settings.general` is linked from App\Support\Manage\Navigation. The rest are
 * reached from the in-page submenu, which is why they carry no rail entry.
 *
 * Reads are `can:manage-admin`, not the group's `can:access-manage`. A reviewer's whole
 * job is the fursuit queue, and how the convention is configured - opening hours, booth
 * ranges, the review wording itself - is not theirs to read; see docs/admin/roles.md. The
 * `canEdit` prop each pane is handed stays, because it is what an admin-only pane still
 * uses to render its own read-only states.
 *
 * WRITES GO IN THE GROUP BELOW, NOT HERE. Both groups are `can:manage-admin` now, so the
 * split is no longer a privilege boundary; it stays because a write also calls
 * `Gate::authorize('manage-admin')` in its own method, which is the belt-and-braces
 * pattern DB Service applies for the same reason: the middleware is what a route audit can
 * see, the in-method gate is what stays attached if these routes are ever regrouped.
 *
 * On-Site Desk is the one pane served by its own controller rather than by
 * SettingsController, because it is the one pane with real fields to save and its two
 * writes belong next to the reads that feed them. It is the successor to the Tools >
 * Pickup Booths page, which is gone: the screen configures the convention rather than
 * running anything, which is the line Tools and Settings are split on.
 */
Route::prefix('settings')->name('settings.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [SettingsController::class, 'general'])->name('general');
    Route::get('on-site-desk', [OnSiteDeskController::class, 'index'])->name('on-site-desk');
    /*
     * The wording the review queue offers and the attendee receives. Its own controller for the
     * same reason On-Site Desk has one: it is a pane with real records to save, and its writes
     * belong next to the reads that feed them.
     */
    Route::get('review-reasons', [ReviewReasonController::class, 'index'])->name('review-reasons');
});

/*
 * The write half. Only On-Site Desk writes anything: `events.desk_opening_hours` and
 * `events.pickup_booths` are the two configurable event columns the Events form has never
 * owned, and no other pane has a field of its own, because a setting nothing reads is
 * worse than no setting and a setting two screens write is worse again.
 */
Route::prefix('settings')->name('settings.')->middleware('can:manage-admin')->group(function () {
    Route::put('on-site-desk/hours', [OnSiteDeskController::class, 'updateHours'])->name('on-site-desk.hours');
    Route::put('on-site-desk/booths', [OnSiteDeskController::class, 'updateBooths'])->name('on-site-desk.booths');
    Route::post('on-site-desk/booths/reset', [OnSiteDeskController::class, 'resetBooths'])->name('on-site-desk.booths.reset');

    /*
     * Review reasons. Deactivating is the way to retire one - the slug stays resolvable in a
     * request log - and delete is there for a reason created by mistake. "Restore defaults" only
     * inserts what is missing, so wording the desk wrote is never overwritten.
     */
    Route::post('review-reasons', [ReviewReasonController::class, 'store'])->name('review-reasons.store');
    Route::put('review-reasons/{reviewReason}', [ReviewReasonController::class, 'update'])
        ->whereNumber('reviewReason')
        ->name('review-reasons.update');
    Route::delete('review-reasons/{reviewReason}', [ReviewReasonController::class, 'destroy'])
        ->whereNumber('reviewReason')
        ->name('review-reasons.destroy');
    Route::post('review-reasons/restore-defaults', [ReviewReasonController::class, 'restoreDefaults'])
        ->name('review-reasons.restore-defaults');
});
