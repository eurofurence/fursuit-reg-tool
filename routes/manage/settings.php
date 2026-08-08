<?php

use App\Http\Controllers\Manage\OnSiteDeskController;
use App\Http\Controllers\Manage\SettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Settings. Configuration only: four panes, one real URL each.
 *
 * The panes are routes rather than client-side tab state, so every one of them is
 * linkable, back-button-able and testable, and so the second vertical menu inside the page
 * body highlights from the URL the same way the primary rail does. `/` renders General
 * rather than redirecting to it: a redirect would put a hop between the rail item and the
 * first pane for no gain, and General is a real pane, not an alias.
 *
 * Only `manage.settings.general` is linked from App\Support\Manage\Navigation. The other
 * three are reached from the in-page submenu, which is why they carry no rail entry.
 *
 * Reads are `can:access-manage`, inherited from the group in bootstrap/app.php, matching
 * every other configuration surface in the panel: a reviewer may look at how the
 * convention is configured. Every pane is also handed `canEdit` from `manage-admin`, so a
 * pane that grows a form renders it read-only for a reviewer.
 *
 * WRITES GO IN THE GROUP BELOW, NOT HERE. Anything that changes configuration is
 * administrative, so it belongs inside the `can:manage-admin` group at the bottom of this
 * file *and* calls `Gate::authorize('manage-admin')` in its own method, which is the
 * belt-and-braces pattern DB Service applies for the same reason: the middleware is what a
 * route audit can see, the in-method gate is what stays attached if these routes are ever
 * regrouped.
 *
 * On-Site Desk is the one pane served by its own controller rather than by
 * SettingsController, because it is the one pane with real fields to save and its two
 * writes belong next to the reads that feed them. It is the successor to the Tools >
 * Pickup Booths page, which is gone: the screen configures the convention rather than
 * running anything, which is the line Tools and Settings are split on.
 */
Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [SettingsController::class, 'general'])->name('general');
    Route::get('on-site-desk', [OnSiteDeskController::class, 'index'])->name('on-site-desk');
    Route::get('printing', [SettingsController::class, 'printing'])->name('printing');
    Route::get('badges', [SettingsController::class, 'badges'])->name('badges');
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
});
