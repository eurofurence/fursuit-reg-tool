<?php

use App\Http\Controllers\Manage\SumUpReaderController;
use Illuminate\Support\Facades\Route;

/*
 * SumUp readers (phase 5). The Filament resource lived at /admin/sum-up-readers with
 * index, create and edit pages; the single delete sat on the Edit page header and was
 * missing from the plan's route table, so it is registered here alongside the bulk one.
 *
 * `reveal` is a POST because it hands out a payment terminal credential: it authorizes its
 * own ability, writes an activity entry and flashes the plaintext for exactly one
 * response. Nothing about the reader changes, but it is a request, not a client-side
 * toggle over data the list already shipped.
 *
 * The bulk route is declared before {reader}, or DELETE /admin/sumup-readers/bulk would
 * bind "bulk" as a route model and 404 before the controller ever sees it.
 */
Route::prefix('sumup-readers')->name('sumup-readers.')->group(function () {
    Route::get('/', [SumUpReaderController::class, 'index'])->name('index');
    Route::get('create', [SumUpReaderController::class, 'create'])->name('create');
    Route::post('/', [SumUpReaderController::class, 'store'])->name('store');
    Route::delete('bulk', [SumUpReaderController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::get('{reader}/edit', [SumUpReaderController::class, 'edit'])->whereNumber('reader')->name('edit');
    Route::put('{reader}', [SumUpReaderController::class, 'update'])->whereNumber('reader')->name('update');
    Route::post('{reader}/reveal', [SumUpReaderController::class, 'reveal'])->whereNumber('reader')->name('reveal');
    Route::delete('{reader}', [SumUpReaderController::class, 'destroy'])->whereNumber('reader')->name('destroy');
});
