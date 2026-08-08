<?php

use App\Http\Controllers\Manage\PrinterController;
use App\Http\Controllers\Manage\PrinterStateController;
use Illuminate\Support\Facades\Route;

/*
 * Printers (phase 6). The screen staff watch during a live print run, so the two writes
 * that are not the record form get their own endpoints on PrinterStateController.
 *
 * `active` is the inline toggle. It is a POST carrying the state it wants rather than a
 * table column that wrote to the database on click (audit 92), and it is deliberately not
 * a PUT on the record: it writes one column, it is not the form, and it must never be
 * confused with a full round-trip of a printer's configuration.
 *
 * `clear-error` sits on top of Printer::clearPrinterError(), which has always existed and
 * which the panel never called (plan 2.10 #27).
 *
 * The bulk route is declared before {printer}, or DELETE /admin/printers/bulk would bind
 * "bulk" as a route model and 404 before the controller ever sees it.
 */
Route::prefix('printers')->name('printers.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [PrinterController::class, 'index'])->name('index');
    Route::get('create', [PrinterController::class, 'create'])->name('create');
    Route::post('/', [PrinterController::class, 'store'])->name('store');
    Route::delete('bulk', [PrinterController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::get('{printer}/edit', [PrinterController::class, 'edit'])->whereNumber('printer')->name('edit');
    Route::put('{printer}', [PrinterController::class, 'update'])->whereNumber('printer')->name('update');
    Route::delete('{printer}', [PrinterController::class, 'destroy'])->whereNumber('printer')->name('destroy');

    Route::post('{printer}/active', [PrinterStateController::class, 'setActive'])
        ->whereNumber('printer')
        ->name('active');
    Route::post('{printer}/clear-error', [PrinterStateController::class, 'clearError'])
        ->whereNumber('printer')
        ->name('clear-error');
});
