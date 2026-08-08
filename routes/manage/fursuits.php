<?php

use App\Http\Controllers\Manage\FursuitController;
use App\Http\Controllers\Manage\FursuitModerationController;
use App\Http\Controllers\Manage\FursuitNotificationController;
use Illuminate\Support\Facades\Route;

/*
 * Fursuits (phase 3). The moderation queue, which is the busiest surface in the panel:
 * a reviewer opens a pending fursuit, claims it, approves or rejects it, and is carried
 * to the next one.
 *
 * Three shapes worth knowing before reading the controllers.
 *
 * There is no create route. FursuitPolicy::create() returns false and stays false
 * (plan 2.2, audit 38), so the Filament create page was unreachable and is not ported.
 *
 * Claim is a POST and unclaim a DELETE on the same `claim` sub-resource, because
 * claiming is now an explicit gesture: ViewFursuit mounted its Claim action on every
 * page load, so merely opening a pending fursuit claimed it (plan 2.10 #41). A GET can
 * no longer take the lock.
 *
 * `next` is a GET that answers with a redirect rather than a page. It is a query over
 * the queue - ordered, event-scoped, skipping claimed records (plan 2.10 #42) - and its
 * answer is "which record do you work on now", so it belongs on the verb that navigates.
 *
 * Every path under {fursuit} is a literal sub-segment, so nothing here can shadow the
 * record route; the parameter is still constrained to digits so a stray word never
 * reaches the binder.
 */
Route::prefix('fursuits')->name('fursuits.')->group(function () {
    Route::get('/', [FursuitController::class, 'index'])->name('index');

    Route::get('{fursuit}', [FursuitController::class, 'show'])->whereNumber('fursuit')->name('show');
    Route::get('{fursuit}/edit', [FursuitController::class, 'edit'])->whereNumber('fursuit')->name('edit');
    Route::put('{fursuit}', [FursuitController::class, 'update'])->whereNumber('fursuit')->name('update');
    Route::delete('{fursuit}', [FursuitController::class, 'destroy'])->whereNumber('fursuit')->name('destroy');

    Route::post('{fursuit}/claim', [FursuitModerationController::class, 'claim'])->whereNumber('fursuit')->name('claim');
    Route::delete('{fursuit}/claim', [FursuitModerationController::class, 'unclaim'])->whereNumber('fursuit')->name('unclaim');
    Route::post('{fursuit}/approve', [FursuitModerationController::class, 'approve'])->whereNumber('fursuit')->name('approve');
    Route::post('{fursuit}/approve-rejected', [FursuitModerationController::class, 'approveRejected'])->whereNumber('fursuit')->name('approve-rejected');
    Route::post('{fursuit}/reject', [FursuitModerationController::class, 'reject'])->whereNumber('fursuit')->name('reject');
    Route::get('{fursuit}/next', [FursuitModerationController::class, 'next'])->whereNumber('fursuit')->name('next');

    Route::post('{fursuit}/notify', [FursuitNotificationController::class, 'store'])->whereNumber('fursuit')->name('notify');
});
