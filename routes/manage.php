<?php

use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\TableColumnController;
use App\Http\Controllers\Manage\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel routes
|--------------------------------------------------------------------------
|
| The Inertia admin panel, and the only one: it serves /admin and the Filament
| panel that used to sit there is gone; see docs/admin/rebuild-plan.md. The
| route names are admin.*, and this group is the only thing that registers
| them. This file holds the panel shell only: the dashboard, the
| session-state endpoints and the shared table endpoint. Every module lives
| in its own file under routes/manage/ and is picked up automatically, so
| the modules of plan part 3 can land phase by phase without ever touching
| this file.
|
| Guests are pushed into the existing Identity SSO flow by `auth`, which is
| what the Filament panel did too since it declared no ->login(). There is no
| /admin/login. A signed-in user without `access-manage` gets a 403.
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
 * Module routes. A new module is added by dropping routes/manage/{module}.php in;
 * nothing here changes. Each file registers inside this group, so it inherits the
 * /admin prefix, the admin.* name prefix and the middleware stack from the group
 * in bootstrap/app.php.
 *
 * Sorted so registration order is the same on every machine, because route
 * matching is order-sensitive: glob() follows the filesystem, which is not.
 * Ordering across modules does not matter today since each owns a distinct URI
 * prefix, but a module that ever needs to match before another can rely on this.
 */
$modules = glob(__DIR__.'/manage/*.php');
sort($modules);

foreach ($modules as $module) {
    require $module;
}
