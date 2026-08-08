<?php

use App\Http\Controllers\Admin\BadgePdfController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\InfoController;
use App\Http\Controllers\WelcomeController;
use App\Http\Middleware\EventEndedMiddleware;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\User;
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

// Admin badge PDF routes (used by Filament).
//
// `can:access-manage` and not `auth` alone: `custom_id` is `{attendee_id}-{n}`, so the
// whole namespace is enumerable and every signed-in attendee could pull any other
// attendee's badge PDF, image, name, species and Catch-Em-All QR code included (audit
// landmine 60, rebuild-plan 2.10 change 20). The gate is `is_admin || is_reviewer`, which
// is the same set `User::canAccessPanel()` lets into the Filament panel that links here,
// so nobody who can reach these links loses them. The routes themselves stay until phase
// 10 retires Filament; `manage.tools.badge-preview.pdf.*` are their successors.
Route::middleware(['auth', 'can:access-manage'])->prefix('admin')->group(function () {
    Route::get('/badge-pdf/{customId}/view', [BadgePdfController::class, 'view'])
        ->name('admin.badge-pdf.view');
    Route::get('/badge-pdf/{customId}/download', [BadgePdfController::class, 'download'])
        ->name('admin.badge-pdf.download');
});

// TEMPORARY local-only sign-in, for driving the admin panel in a browser
// without going through OIDC. Guarded by the environment and by a signed URL,
// and removed as soon as it has been used.
if (app()->environment('local')) {
    Route::get('/dev-login/{user}', function (User $user) {
        auth()->login($user);

        return redirect('/admin');
    })->middleware('signed')->name('dev.login');

    // POS needs three guards, not one: the web user plus a machine and the
    // staff member operating it.
    Route::get('/dev-login-pos/{user}/{machine}/{staff}', function (User $user, Machine $machine, Staff $staff) {
        auth()->login($user);
        auth()->guard('machine')->login($machine);
        // The machine-user guard is backed by Staff, not User.
        auth()->guard('machine-user')->login($staff);

        return redirect('/pos');
    })->middleware('signed')->name('dev.login.pos');
}

// TEMPORARY: side-by-side parity harness for the PrimeVue -> Components/UI
// migration. Delete together with resources/js/Pages/Dev/UiParity.vue.
if (app()->environment('local')) {
    Route::get('/dev-ui-parity', fn () => inertia('Dev/UiParity'))->name('dev.ui.parity');

    // APP_DEBUG=true means a real 404 never reaches Pages/Error.vue locally,
    // so the migrated page is rendered directly.
    Route::get('/dev-ui-error', fn () => inertia('Error', ['status' => 419, 'message' => 'Session expired']))->name('dev.ui.error');
}
