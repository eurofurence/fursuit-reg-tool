<?php

use App\Http\Controllers\Manage\PrintBatchController;
use App\Http\Controllers\Manage\PrintBatchRunController;
use Illuminate\Support\Facades\Route;

/*
 * Print batches (phase 7, audit 4.8). The run a printer works through card by card.
 *
 * Two GETs and five POSTs, and the split between them is the point. A batch is immutable
 * once built: there is no create, no store, no edit, no update and no destroy, because the
 * only thing that can populate one is PrintBatch::build(), which freezes the print order
 * and locks every badge in it at the same moment. The old panel resource says the same with
 * `canCreate(): false`. `retry` does not break that: it opens a *new* batch from the same
 * badges rather than refilling the failed one.
 *
 * The five mutations live on PrintBatchRunController rather than on the read controller, so
 * nothing that halts, restarts or cancels a live convention print run can be reached by
 * opening a page, by the ten-second poll behind the list, or by a link somebody pasted into
 * a chat. There is no GET form of any of them.
 *
 * `verify` is nested under its batch on purpose. The card belongs to the run, the ability
 * is asked of the run (PrintBatchPolicy::verify, mirroring what an old panel relation manager
 * did), and the controller refuses a job that belongs to a different batch rather than
 * trusting the id pair.
 *
 * Literal segments would have to come before {print_batch}; there are none here, and the
 * numeric constraint keeps it that way if one is ever added.
 */
Route::prefix('print-batches')->name('print-batches.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [PrintBatchController::class, 'index'])->name('index');
    Route::get('{print_batch}', [PrintBatchController::class, 'show'])->whereNumber('print_batch')->name('show');

    Route::post('{print_batch}/pause', [PrintBatchRunController::class, 'pause'])
        ->whereNumber('print_batch')
        ->name('pause');

    Route::post('{print_batch}/resume', [PrintBatchRunController::class, 'resume'])
        ->whereNumber('print_batch')
        ->name('resume');

    Route::post('{print_batch}/cancel', [PrintBatchRunController::class, 'cancel'])
        ->whereNumber('print_batch')
        ->name('cancel');

    // Queues the same badges again after a preparation failed. A POST that creates a new
    // batch, so it is a mutation like the three above and lives on the same controller.
    Route::post('{print_batch}/retry', [PrintBatchRunController::class, 'retry'])
        ->whereNumber('print_batch')
        ->name('retry');

    Route::post('{print_batch}/jobs/{print_job}/verify', [PrintBatchRunController::class, 'verify'])
        ->whereNumber('print_batch')
        ->whereNumber('print_job')
        ->name('jobs.verify');
});
