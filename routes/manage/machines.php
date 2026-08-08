<?php

use App\Http\Controllers\Manage\MachineController;
use App\Http\Controllers\Manage\MachineLoginLinkController;
use Illuminate\Support\Facades\Route;

/*
 * Machines (phase 5). MachineResource had index, create and edit pages and no delete of
 * any kind; that is kept (audit 131).
 *
 * Archive and restore are the same URI under two verbs, POST to hide and DELETE to bring
 * back, single and bulk. The bulk pair is declared before {machine}, or POST
 * /admin/machines/bulk/archive would bind "bulk" as a route model and 404 before the
 * controller ever saw it.
 *
 * The login link is a credential and gets its own endpoint, minted only when it is
 * called. Nothing renders it into a page payload.
 */
Route::prefix('machines')->name('machines.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [MachineController::class, 'index'])->name('index');
    Route::get('create', [MachineController::class, 'create'])->name('create');
    Route::post('/', [MachineController::class, 'store'])->name('store');

    Route::post('bulk/archive', [MachineController::class, 'bulkArchive'])->name('bulk.archive');
    Route::delete('bulk/archive', [MachineController::class, 'bulkUnarchive'])->name('bulk.unarchive');

    Route::get('{machine}/edit', [MachineController::class, 'edit'])->whereNumber('machine')->name('edit');
    Route::put('{machine}', [MachineController::class, 'update'])->whereNumber('machine')->name('update');
    Route::post('{machine}/archive', [MachineController::class, 'archive'])->whereNumber('machine')->name('archive');
    Route::delete('{machine}/archive', [MachineController::class, 'unarchive'])->whereNumber('machine')->name('unarchive');
    Route::post('{machine}/login-link', MachineLoginLinkController::class)->whereNumber('machine')->name('login-link');
});
