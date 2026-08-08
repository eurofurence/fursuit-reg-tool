<?php

use App\Http\Controllers\Manage\EventController;
use Illuminate\Support\Facades\Route;

/*
 * Events (phase 2). ManageEvents was a ManageRecords page with create and edit in modals,
 * so neither had a URL; both are real pages here (plan 1.2).
 *
 * UNDER /admin/settings, NOT /admin. Events is edited a handful of times per convention and
 * every field on it configures the convention rather than running it, which is exactly the
 * line Settings is drawn on, so it is a Settings pane rather than a rail entry of its own.
 * The URL follows the menu: a pane reached from the Settings submenu whose path sat outside
 * /admin/settings would leave the rail highlighting nothing while the pane was open. The
 * routes stay in their own file because the module is still a full list-plus-form CRUD, not
 * a settings form.
 *
 * There is no route for event state: it is computed by Event::state() from the date
 * fields, so the only way to change it is through the ordinary update below.
 *
 * `bulk` is declared before `{event}` for the same reason as in users.php, and the
 * parameter is constrained to digits so a stray word cannot reach the binder at all.
 */
Route::prefix('settings/events')->name('settings.events.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [EventController::class, 'index'])->name('index');
    Route::get('create', [EventController::class, 'create'])->name('create');
    Route::post('/', [EventController::class, 'store'])->name('store');
    Route::delete('bulk', [EventController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::get('{event}/edit', [EventController::class, 'edit'])->whereNumber('event')->name('edit');
    Route::put('{event}', [EventController::class, 'update'])->whereNumber('event')->name('update');
    Route::delete('{event}', [EventController::class, 'destroy'])->whereNumber('event')->name('destroy');
});
