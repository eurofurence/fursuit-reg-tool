<?php

use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\SpecialCodeController;
use App\Http\Controllers\Manage\TableColumnController;
use App\Http\Controllers\Manage\UploadController;
use App\Http\Controllers\Manage\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Manage routes
|--------------------------------------------------------------------------
|
| The Inertia admin panel. It serves /admin; the Filament panel has moved to
| /admin-legacy and keeps running there until the parity suite is green; see
| docs/admin/rebuild-plan.md. The route names stay manage.* until part 5
| removes Filament, because admin.* still belongs to admin.badge-pdf.* in
| routes/web.php. Phase 0 registers the dashboard and the two session-state
| endpoints only. The module routes land phase by phase, in the order of
| plan part 3.
|
| Guests are pushed into the existing Identity SSO flow by `auth`, which is
| what the Filament panel already does since it declares no ->login(). There
| is no /admin/login. A signed-in user without `access-manage` gets a 403.
|
| Every mutation is a POST/PUT/DELETE that redirects back with a flash. No
| JSON endpoints: data reaches the client through Inertia props.
|
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

/*
 * The global event scope. A POST rather than the old `?selected_event_id=`
 * query-string side effect on an arbitrary GET, so an unknown id is a
 * validation error instead of a poisoned session. See plan 2.9.
 */
Route::post('event', [DashboardController::class, 'selectEvent'])->name('event.select');

Route::post('uploads', [UploadController::class, 'store'])->name('uploads.store');

Route::post('tables/{table}/columns', [TableColumnController::class, 'update'])
    ->where('table', '[a-z0-9_-]+')
    ->name('tables.columns');

/*
 * Users (phase 1). Create, edit and delete were Filament modals on a ManageRecords page,
 * so the resource had no create or edit URL at all; they are real pages here (plan 1.2).
 *
 * The bulk route is declared before {user}, or DELETE /admin/users/bulk would bind "bulk"
 * as a route model and 404 before the controller ever sees it.
 */
Route::prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::get('create', [UserController::class, 'create'])->name('create');
    Route::post('/', [UserController::class, 'store'])->name('store');
    Route::delete('bulk', [UserController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::get('{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('edit');
    Route::put('{user}', [UserController::class, 'update'])->whereNumber('user')->name('update');
    Route::delete('{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('destroy');
});

/*
 * Special codes (phase 1). Same shape and the same reason: ManageSpecialCodes was an
 * index page with create and edit in modals, so neither had a URL (plan 1.2).
 *
 * `bulk` is declared before `{code}` for the same reason as above, and the parameter is
 * constrained to digits so a stray word cannot reach the binder at all.
 */
Route::prefix('special-codes')->name('special-codes.')->group(function () {
    Route::get('/', [SpecialCodeController::class, 'index'])->name('index');
    Route::get('create', [SpecialCodeController::class, 'create'])->name('create');
    Route::post('/', [SpecialCodeController::class, 'store'])->name('store');
    Route::delete('bulk', [SpecialCodeController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::get('{code}/edit', [SpecialCodeController::class, 'edit'])->whereNumber('code')->name('edit');
    Route::put('{code}', [SpecialCodeController::class, 'update'])->whereNumber('code')->name('update');
    Route::delete('{code}', [SpecialCodeController::class, 'destroy'])->whereNumber('code')->name('destroy');
});
