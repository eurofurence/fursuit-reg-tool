<?php

use App\Http\Controllers\Manage\BadgeController;
use App\Http\Controllers\Manage\BadgePrintController;
use App\Http\Controllers\Manage\BadgeVerificationController;
use Illuminate\Support\Facades\Route;

/*
 * Badges (phase 4, audit 4.2). The biggest resource in the old panel.
 *
 * Two of the old badge list's pages are missing here on purpose. There is no create route:
 * `fursuit_id` was disabled on that form, disabled fields do not dehydrate, and
 * `badges.fursuit_id` is NOT NULL with a foreign key, so creating a badge from admin has
 * always thrown an integrity error. And there is no `print` or
 * `bulk/print`: the badge print pipeline lands in phase 7 against BadgePrintQueue, so the
 * whole print path is reviewed in one PR.
 *
 * The `{badge}` parameter is constrained to digits, so a stray word never reaches the
 * binder and a literal segment can never bind as a record id.
 *
 * The two print endpoints land in phase 7, against `BadgePrintQueue`. Both
 * are POSTs on BadgePrintController, never GETs and never a side effect of the list's
 * five-second poll: queueing a card is an explicit, authorised gesture. `bulk/print` is a
 * literal segment and is declared before `{badge}` so it can never bind "bulk" as a badge.
 */
/*
 * Reads are the group's `can:access-manage`, so a reviewer keeps the list and the detail.
 * Everything that changes a badge or queues a card is `can:manage-admin`: a reviewer works
 * the fursuit queue and reads badges to do it, and moving a badge between fulfillment
 * states or sending cards to a printer is desk work, not review work. See
 * docs/admin/roles.md.
 *
 * `{badge}/edit` is deliberately in the open half. It is the only badge detail the panel
 * has - there is no separate show page - so gating it would take the record away, not just
 * the form. BadgeController::edit authorizes `view` and hands the page a `canEdit` flag,
 * and the PUT below is what actually refuses the write.
 */
Route::prefix('badges')->name('badges.')->group(function () {
    Route::get('/', [BadgeController::class, 'index'])->name('index');
    Route::get('{badge}/edit', [BadgeController::class, 'edit'])->whereNumber('badge')->name('edit');

    Route::middleware('can:manage-admin')->group(function () {
        Route::post('bulk/print', [BadgePrintController::class, 'bulk'])->name('bulk.print');
        Route::post('bulk/status', [BadgeController::class, 'bulkStatus'])->name('bulk.status');
        Route::post('{badge}/print', [BadgePrintController::class, 'store'])->whereNumber('badge')->name('print');
        // The inline check-off column. A POST carrying the state it wants, never a PUT on
        // the record: it writes one column and it is not the form.
        Route::post('{badge}/verify', [BadgeVerificationController::class, 'update'])->whereNumber('badge')->name('verify');
        Route::put('{badge}', [BadgeController::class, 'update'])->whereNumber('badge')->name('update');
        Route::delete('{badge}', [BadgeController::class, 'destroy'])->whereNumber('badge')->name('destroy');
    });
});
