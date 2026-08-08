<?php

use App\Http\Controllers\Manage\TseClientController;
use Illuminate\Support\Facades\Route;

/*
 * TSE clients (phase 8). Two GETs and three lifecycle writes.
 *
 * The Filament resource's edit form does not come across, and neither does a delete.
 * `remote_id` and `serial_number` are the signing identity past checkouts were signed
 * under (plan 2.10 #14), so nothing here rewrites them and nothing removes a client whose
 * serial receipts still point at. Only `state` moves, and it moves through Fiskaly.
 *
 * What is new is registration. `createnew` was dropped because it fabricated a row from a
 * random UUID and never called anyone (plan 2.10 #13, audit landmine 7) - not because
 * issuing a client is something the panel may not do. `store` does the same job properly:
 * one button, no fields to get wrong, and the row only survives if the TSS accepted it.
 * `register` and `deregister` are the two ends of the yearly cycle, which is normally
 * bringing the previous convention's client back rather than issuing another.
 *
 * Register and deregister are the same URI under two verbs, POST to bring a client into
 * service and DELETE to take it out. The literal `/` POST is declared before `{client}`
 * so it cannot be bound as a route model.
 *
 * `php artisan tse:update-state` and `php artisan tse:change-admin-pin` are still the way
 * the TSS itself - as opposed to a client on it - is driven.
 */
Route::prefix('tse-clients')->name('tse-clients.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [TseClientController::class, 'index'])->name('index');
    Route::post('/', [TseClientController::class, 'store'])->name('store');

    Route::get('{client}', [TseClientController::class, 'show'])->whereNumber('client')->name('show');
    Route::post('{client}/register', [TseClientController::class, 'register'])->whereNumber('client')->name('register');
    Route::delete('{client}/register', [TseClientController::class, 'deregister'])->whereNumber('client')->name('deregister');
});
