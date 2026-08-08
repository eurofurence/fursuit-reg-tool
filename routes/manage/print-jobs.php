<?php

use App\Http\Controllers\Manage\PrintJobController;
use App\Http\Controllers\Manage\PrintJobResetController;
use App\Http\Controllers\Manage\PrintJobRetryController;
use Illuminate\Support\Facades\Route;

/*
 * Print jobs (phase 6, audit 4.9). The queue behind the card printers.
 *
 * Two shapes worth knowing before reading the controllers.
 *
 * `retry` is a POST on its own sub-resource, handled by its own controller. It is the
 * only endpoint here that puts another card through a printer, so it can never be reached
 * by opening a page, by a poll, or by a link somebody pasted into a chat. There is no GET
 * form of it.
 *
 * `?printer=` is gone. It was a resource-wide getEloquentQuery() scope that applied to the
 * view and edit pages as well and had no chip to clear it; it is `filter[printer]` now
 *, and the list still renames itself `Print Jobs - {name}` while it
 * is on.
 *
 * The bulk routes are declared before {print_job}, or DELETE /admin/print-jobs/bulk would
 * bind "bulk" as a route model and 404 before the controller ever sees it. `create` is
 * declared first for the same reason.
 *
 * `bulk/reset` is the second endpoint here that can put a card through a printer, and it
 * has its own controller for the same reason `retry` does. It is a POST, never a GET.
 */
Route::prefix('print-jobs')->name('print-jobs.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [PrintJobController::class, 'index'])->name('index');
    Route::get('create', [PrintJobController::class, 'create'])->name('create');
    Route::post('/', [PrintJobController::class, 'store'])->name('store');
    Route::delete('bulk', [PrintJobController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::post('bulk/reset', [PrintJobResetController::class, 'bulk'])->name('bulk.reset');

    Route::get('{print_job}', [PrintJobController::class, 'show'])->whereNumber('print_job')->name('show');
    Route::get('{print_job}/edit', [PrintJobController::class, 'edit'])->whereNumber('print_job')->name('edit');
    Route::put('{print_job}', [PrintJobController::class, 'update'])->whereNumber('print_job')->name('update');
    Route::delete('{print_job}', [PrintJobController::class, 'destroy'])->whereNumber('print_job')->name('destroy');

    Route::post('{print_job}/retry', [PrintJobRetryController::class, 'store'])->whereNumber('print_job')->name('retry');
});
