<?php

use App\Http\Controllers\Admin\BadgePdfController;
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

// Admin badge PDF routes.
//
// `can:access-manage` and not `auth` alone: `custom_id` is `{attendee_id}-{n}`, so the
// whole namespace is enumerable and every signed-in attendee could pull any other
// attendee's badge PDF, image, name, species and Catch-Em-All QR code included (audit
// landmine 60, rebuild-plan 2.10 change 20). The gate is `is_admin || is_reviewer`, the
// same set the retired Filament panel admitted, so nobody who could reach these links
// loses them. `manage.tools.badge-preview.pdf.*` are their successors; these two names
// still own the `admin.` prefix, which is why the panel routes stay `manage.*` until the
// rename phase.
Route::middleware(['auth', 'can:access-manage'])->prefix('admin')->group(function () {
    Route::get('/badge-pdf/{customId}/view', [BadgePdfController::class, 'view'])
        ->name('admin.badge-pdf.view');
    Route::get('/badge-pdf/{customId}/download', [BadgePdfController::class, 'download'])
        ->name('admin.badge-pdf.download');
});

// The Filament panel used to sit here. It is gone; the Inertia panel owns /admin.
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
