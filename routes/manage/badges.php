<?php

use App\Http\Controllers\Manage\BadgeController;
use App\Http\Controllers\Manage\BadgePrintController;
use Illuminate\Support\Facades\Route;

/*
 * Badges (phase 4, audit 4.2). The biggest resource in the old panel.
 *
 * Two of BadgeResource's pages are missing here on purpose. There is no create route:
 * `fursuit_id` was disabled on that form, disabled fields do not dehydrate, and
 * `badges.fursuit_id` is NOT NULL with a foreign key, so creating a badge from admin has
 * always thrown an integrity error (plan 2.10 #6). And there is no `print` or
 * `bulk/print`: the badge print pipeline lands in phase 7 against BadgePrintQueue, so the
 * whole print path is reviewed in one PR (plan part 3).
 *
 * `corrupted-totals` is the read-only money report plan 2.10 #3 asks phase 4 to ship. It
 * is declared before `{badge}` for the usual reason: otherwise /admin/badges/corrupted-totals
 * binds "corrupted-totals" as a badge id and 404s. The parameter is constrained to digits
 * as well, so a stray word never reaches the binder.
 *
 * The two print endpoints land in phase 7 (plan part 3), against `BadgePrintQueue`. Both
 * are POSTs on BadgePrintController, never GETs and never a side effect of the list's
 * five-second poll: queueing a card is an explicit, authorised gesture. `bulk/print` is a
 * literal segment and is declared before `{badge}` so it can never bind "bulk" as a badge.
 */
Route::prefix('badges')->name('badges.')->group(function () {
    Route::get('/', [BadgeController::class, 'index'])->name('index');
    Route::get('corrupted-totals', [BadgeController::class, 'corruptedTotals'])->name('corrupted-totals');
    Route::post('bulk/print', [BadgePrintController::class, 'bulk'])->name('bulk.print');
    Route::get('{badge}/edit', [BadgeController::class, 'edit'])->whereNumber('badge')->name('edit');
    Route::post('{badge}/print', [BadgePrintController::class, 'store'])->whereNumber('badge')->name('print');
    Route::put('{badge}', [BadgeController::class, 'update'])->whereNumber('badge')->name('update');
    Route::delete('{badge}', [BadgeController::class, 'destroy'])->whereNumber('badge')->name('destroy');
});
