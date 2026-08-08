<?php

use App\Http\Controllers\Manage\FursuitController;
use App\Http\Controllers\Manage\FursuitModerationController;
use App\Http\Controllers\Manage\FursuitNotificationController;
use App\Http\Controllers\Manage\FursuitReviewController;
use Illuminate\Support\Facades\Route;

/*
 * Fursuits (phase 3). The moderation queue, which is the busiest surface in the panel:
 * a reviewer opens the queue, judges a fursuit, and is carried to the next one.
 *
 * Four shapes worth knowing before reading the controllers.
 *
 * There is no create route. FursuitPolicy::create() returns false and stays false
 * (plan 2.2, audit 38), so the Filament create page was unreachable and is not ported.
 *
 * `review` is the queue surface and lives beside the record pages rather than replacing
 * them: /admin/fursuits/{id} is the record - infolist, activity log, every action - and
 * /admin/fursuits/review/{id} is the one-at-a-time page a reviewer works an afternoon in.
 * The bare `review` is a redirect that answers "which record now".
 *
 * There is no claim route. The Filament page took a five-minute cache lock on page load
 * and refused every verdict unless the caller held it, so a reviewer who opened a record
 * by link could do nothing with it and a dead browser froze the record for five minutes
 * (plan 2.10 #41, audit 69/71). App\Services\FursuitPresence replaced it: the queue skips
 * records somebody is on and the page says who else is there, but nothing is ever refused.
 * Presence needs no endpoint of its own - the review page refreshes it, so the client's
 * poll is the heartbeat.
 *
 * `next` is a GET that answers with a redirect rather than a page. It is a query over the
 * queue - ordered, event-scoped, skipping records somebody is on (plan 2.10 #42) - and its
 * answer is "which record do you work on now", so it belongs on the verb that navigates.
 * The three verdicts redirect the same way, and carry `queue=1` when they came from the
 * queue so a reviewer working it keeps working it.
 *
 * Every path under {fursuit} is a literal sub-segment, so nothing here can shadow the
 * record route; the parameter is still constrained to digits so a stray word never
 * reaches the binder.
 */
Route::prefix('fursuits')->name('fursuits.')->group(function () {
    Route::get('/', [FursuitController::class, 'index'])->name('index');

    /*
     * Declared before the record routes. Not strictly needed - {fursuit} is
     * digits-only - but the queue is the surface people reach for first, and a
     * reader should not have to check a regex to know that /fursuits/review is
     * not a fursuit called "review".
     */
    Route::get('review', [FursuitReviewController::class, 'index'])->name('review');
    Route::post('review/undo', [FursuitReviewController::class, 'undo'])->name('review.undo');
    Route::get('review/{fursuit}', [FursuitReviewController::class, 'show'])
        ->whereNumber('fursuit')
        ->name('review.show');

    Route::get('{fursuit}', [FursuitController::class, 'show'])->whereNumber('fursuit')->name('show');

    /*
     * Editing the row is not reviewing it. FursuitPolicy has answered `update` and `delete`
     * with is_admin since the rebuild - the reviewer's verdicts below go through the
     * moderation controller, which authorizes `view` - so these three were already refused
     * in the controller. `can:manage-admin` puts that on the route table too, where a route
     * audit can see it without reading three method bodies; see docs/admin/roles.md.
     */
    Route::middleware('can:manage-admin')->group(function () {
        Route::get('{fursuit}/edit', [FursuitController::class, 'edit'])->whereNumber('fursuit')->name('edit');
        Route::put('{fursuit}', [FursuitController::class, 'update'])->whereNumber('fursuit')->name('update');
        Route::delete('{fursuit}', [FursuitController::class, 'destroy'])->whereNumber('fursuit')->name('destroy');
    });

    // The three verdicts. Approve and reject existed; the publication block is the outcome
    // that did not, and is the reason a gallery rule no longer costs an attendee a badge.
    Route::post('{fursuit}/approve', [FursuitModerationController::class, 'approve'])->whereNumber('fursuit')->name('approve');
    Route::post('{fursuit}/reject', [FursuitModerationController::class, 'reject'])->whereNumber('fursuit')->name('reject');
    Route::post('{fursuit}/block-publication', [FursuitModerationController::class, 'blockPublication'])->whereNumber('fursuit')->name('block-publication');

    // Corrections of a verdict rather than verdicts: neither writes a decision row and
    // neither can be undone.
    Route::delete('{fursuit}/block-publication', [FursuitModerationController::class, 'unblockPublication'])->whereNumber('fursuit')->name('unblock-publication');
    Route::post('{fursuit}/approve-rejected', [FursuitModerationController::class, 'approveRejected'])->whereNumber('fursuit')->name('approve-rejected');

    Route::get('{fursuit}/next', [FursuitModerationController::class, 'next'])->whereNumber('fursuit')->name('next');

    Route::post('{fursuit}/notify', [FursuitNotificationController::class, 'store'])->whereNumber('fursuit')->name('notify');
});
