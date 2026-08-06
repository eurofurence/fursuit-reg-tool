<?php

use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\TableColumnController;
use App\Http\Controllers\Manage\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Manage routes
|--------------------------------------------------------------------------
|
| The Inertia admin panel. It runs in parallel with the Filament panel at
| /admin until the parity suite is green; see docs/admin/rebuild-plan.md.
| Phase 0 registers the dashboard and the two session-state endpoints only.
| The module routes land phase by phase, in the order of plan part 3.
|
| Guests are pushed into the existing Identity SSO flow by `auth`, which is
| what the Filament panel already does since it declares no ->login(). There
| is no /manage/login. A signed-in user without `access-manage` gets a 403.
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
