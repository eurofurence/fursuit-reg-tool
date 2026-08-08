<?php

use App\Http\Controllers\Manage\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Users (phase 1). Create, edit and delete were Filament modals on a ManageRecords page,
 * so the resource had no create or edit URL at all; they are real pages here (plan 1.2).
 *
 * UNDER /admin/settings, NOT /admin, for the reason Events is (routes/manage/events.php):
 * an account is created a handful of times per convention and its fields decide who may
 * work the panel rather than running anything through it, which is the line Settings is
 * drawn on. The URL follows the menu - a pane reached from the Settings submenu whose path
 * sat outside /admin/settings would leave that submenu highlighting the wrong card - and
 * the routes stay in their own file because the module is a full list-plus-form CRUD
 * rather than a settings form.
 *
 * The bulk route is declared before {user}, or DELETE /admin/settings/users/bulk would bind
 * "bulk" as a route model and 404 before the controller ever sees it.
 */
Route::prefix('settings/users')->name('settings.users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::get('create', [UserController::class, 'create'])->name('create');
    Route::post('/', [UserController::class, 'store'])->name('store');
    Route::delete('bulk', [UserController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::get('{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('edit');
    Route::put('{user}', [UserController::class, 'update'])->whereNumber('user')->name('update');
    Route::delete('{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('destroy');
});
