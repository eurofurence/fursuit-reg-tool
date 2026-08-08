<?php

use App\Http\Controllers\Manage\RfidTagController;
use App\Http\Controllers\Manage\StaffController;
use App\Http\Controllers\Manage\StaffSetupCodeController;
use Illuminate\Support\Facades\Route;

/*
 * POS staff and their RFID tags (phase 5).
 *
 * The tags have no index and no edit page of their own. They only ever existed as a
 * relation manager on the Staff edit page, and a tag means nothing away from the member
 * it logs in, so they keep four write endpoints nested under that member and are rendered
 * as part of that page.
 *
 * `setup-code` is a POST that writes nothing. It proposes an unused code back into the
 * form, because the Filament suffix action persisted one the moment it was pressed
 * (plan 2.10 #23). It is declared twice: the plan's table names the record-scoped route,
 * and the button is offered on the create screen too, where there is no record to scope
 * to. Both hit the same controller.
 *
 * There is no delete of any kind, single or bulk. A staff row is the only link between a
 * badge handout, a checkout or a print run and the person who did it, and all three
 * foreign keys are `nullOnDelete`, so removing a member erased their statistics without
 * saying so. Archive and restore take its place: the same URI under two verbs, POST to
 * retire and DELETE to bring back, single and bulk, matching machines.
 *
 * Literal segments are declared before the parameter ones, or POST /admin/staff/bulk and
 * POST /admin/staff/setup-code would bind "bulk" and "setup-code" as staff records and
 * 404 before a controller ever ran. `->scopeBindings()` on the nested group resolves
 * {rfidTag} through $staff->rfidTags(), so a tag belonging to another member is a 404
 * rather than something the controller has to remember to check.
 */
Route::prefix('staff')->name('staff.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [StaffController::class, 'index'])->name('index');
    Route::get('create', [StaffController::class, 'create'])->name('create');
    Route::post('/', [StaffController::class, 'store'])->name('store');
    Route::post('setup-code', [StaffSetupCodeController::class, 'store'])->name('setup-code.create');

    Route::post('bulk/archive', [StaffController::class, 'bulkArchive'])->name('bulk.archive');
    Route::delete('bulk/archive', [StaffController::class, 'bulkUnarchive'])->name('bulk.unarchive');

    Route::get('{staff}/edit', [StaffController::class, 'edit'])->whereNumber('staff')->name('edit');
    Route::put('{staff}', [StaffController::class, 'update'])->whereNumber('staff')->name('update');
    Route::post('{staff}/archive', [StaffController::class, 'archive'])->whereNumber('staff')->name('archive');
    Route::delete('{staff}/archive', [StaffController::class, 'unarchive'])->whereNumber('staff')->name('unarchive');
    Route::post('{staff}/setup-code', [StaffSetupCodeController::class, 'store'])->whereNumber('staff')->name('setup-code');

    Route::prefix('{staff}/rfid-tags')->name('rfid-tags.')->whereNumber('staff')->scopeBindings()->group(function () {
        Route::post('/', [RfidTagController::class, 'store'])->name('store');
        Route::delete('bulk', [RfidTagController::class, 'bulkDestroy'])->name('bulk.destroy');
        Route::put('{rfidTag}', [RfidTagController::class, 'update'])->whereNumber('rfidTag')->name('update');
        Route::delete('{rfidTag}', [RfidTagController::class, 'destroy'])->whereNumber('rfidTag')->name('destroy');
    });
});
