<?php

use App\Http\Controllers\Manage\UserProfileController;
use Illuminate\Support\Facades\Route;

/*
 * Catch-Em-All profiles. The second review queue in the panel, and the successor to the
 * old panel's UserProfileResource.
 *
 * No create, no edit, no delete. A profile row is created with the account
 * (App\Observers\UserObserver) and its contents are written by the attendee on
 * /catch-em-all; the panel only decides whether they are shown. UserProfilePolicy still
 * answers `update` and `delete` with is_admin, so a later admin-only form has a gate
 * waiting, but nothing here routes to one.
 *
 * The group carries no `can:manage-admin`: reviewing a profile is review work, so the
 * whole module is open to a reviewer the way the fursuit queue is. See docs/admin/roles.md.
 *
 * `review` is declared before {userProfile}, and the parameter is digits-only, so
 * /admin/profiles/review can never bind a profile called "review".
 */
Route::prefix('profiles')->name('profiles.')->group(function () {
    Route::get('/', [UserProfileController::class, 'index'])->name('index');

    Route::get('review', [UserProfileController::class, 'review'])->name('review');

    Route::get('{userProfile}', [UserProfileController::class, 'show'])
        ->whereNumber('userProfile')
        ->name('show');

    // The three verdicts, plus the two ways to leave a profile alone: release the claim
    // and stay, or drop it and take the next one.
    Route::post('{userProfile}/approve', [UserProfileController::class, 'approve'])->whereNumber('userProfile')->name('approve');
    Route::post('{userProfile}/reject', [UserProfileController::class, 'reject'])->whereNumber('userProfile')->name('reject');
    Route::post('{userProfile}/reopen', [UserProfileController::class, 'reopen'])->whereNumber('userProfile')->name('reopen');
    Route::delete('{userProfile}/claim', [UserProfileController::class, 'unclaim'])->whereNumber('userProfile')->name('unclaim');
    Route::get('{userProfile}/next', [UserProfileController::class, 'next'])->whereNumber('userProfile')->name('next');
});
