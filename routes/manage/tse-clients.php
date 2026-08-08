<?php

use App\Http\Controllers\Manage\TseClientController;
use Illuminate\Support\Facades\Route;

/*
 * TSE clients (phase 8). Two GETs, and that is the whole module.
 *
 * The Filament resource lived at /admin/tse-clients with index, create and edit pages.
 * None of the three writes survives. `createnew` fabricated a client that Fiskaly had
 * never heard of (plan 2.10 #13) and the form rewrote the signing identity of a security
 * module that past checkouts were signed under (plan 2.10 #14), so `remote_id`,
 * `serial_number` and `state` are read-only and there is nothing left for a PUT to carry.
 * The plan's route table still lists `edit` and `update`; they are dropped here because
 * change #14 empties them, and an inert PUT on a fiscal record is a write path waiting to
 * be filled in.
 *
 * There is no DELETE either. Audit 133 records that the Filament edit page kept the stock
 * DeleteAction off only by overriding `getHeaderActions()` to an empty array; here the
 * route simply does not exist.
 *
 * The real lifecycle is `php artisan tse:update-state` and `php artisan tse:change-admin-pin`,
 * which talk to the TSE. This panel shows what those produced.
 */
Route::prefix('tse-clients')->name('tse-clients.')->group(function () {
    Route::get('/', [TseClientController::class, 'index'])->name('index');
    Route::get('{client}', [TseClientController::class, 'show'])->whereNumber('client')->name('show');
});
