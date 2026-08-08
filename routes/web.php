<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\InfoController;
use App\Http\Controllers\WelcomeController;
use App\Http\Middleware\EventEndedMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');
Route::redirect('/auth-login', '/auth/login')->name('login');
Route::redirect('/auth-done', '/')->name('dashboard');

Route::get('/fcea', function () {
    $catchDomain = config('fcea.domain');
    $protocol = str_contains($catchDomain, 'localhost') ? 'http' : 'https';

    return redirect($protocol.'://'.$catchDomain);
});

// Public information pages. `/catch-em-all` used to bounce straight to the game
// subdomain; it now explains the game first and offers the jump as a button, because
// the redirect dropped people into a separate app with a separate login and no context.
// `/fcea` above stays a bare redirect for QR codes and printed material.
Route::get('/catch-em-all', [InfoController::class, 'catchEmAll'])->name('info.catch-em-all');
Route::get('/faq', [InfoController::class, 'faq'])->name('info.faq');
Route::get('/pickup', [InfoController::class, 'pickup'])->name('info.pickup');

Route::middleware(EventEndedMiddleware::class)->group(function () {
    Route::prefix('/auth')->name('auth.')->group(function () {
        Route::get('/login', [AuthController::class, 'show'])->middleware('guest')->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('guest')->name('login.redirect');
        Route::get('/callback', [AuthController::class, 'loginCallback'])->middleware('guest')->name('login.callback');
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
        Route::get('/frontchannel-logout', [AuthController::class, 'logoutCallback'])->name('logout.callback');
    });

    Route::middleware(['auth', 'ensure-event-user'])->group(function () {
        Route::resource('badges', BadgeController::class);
        Route::post('/badges/refresh-prepaid', [BadgeController::class, 'refreshPrepaidBadges'])
            ->name('badges.refresh-prepaid')
            ->middleware('throttle:3,1'); // 3 requests per minute per user
    });
});

// The badge PDF routes used to be a second `/admin` group here, named admin.badge-pdf.*
// by hand. The panel now owns the `admin.` name prefix, so they moved into it: they are
// registered in routes/manage/tools.php beside the badge-preview PDF routes and keep both
// their URLs and their names. See docs/admin/rebuild-plan.md part 5 step 14.

// The old panel used to sit here. It is gone; the Inertia panel owns /admin.
// Kept for one release so bookmarked deep links land on the new panel instead of a 404.
Route::redirect('/admin-legacy/{path?}', '/admin', 301)->where('path', '.*');

// TEMPORARY: side-by-side parity harness for the PrimeVue -> Components/UI
// migration. Delete together with resources/js/Pages/Dev/UiParity.vue.
if (app()->environment('local')) {
    Route::get('/dev-ui-parity', fn () => inertia('Dev/UiParity'))->name('dev.ui.parity');

    // APP_DEBUG=true means a real 404 never reaches Pages/Error.vue locally,
    // so the migrated page is rendered directly.
    Route::get('/dev-ui-error', fn () => inertia('Error', ['status' => 419, 'message' => 'Session expired']))->name('dev.ui.error');
}
