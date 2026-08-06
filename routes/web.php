<?php

use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');
Route::redirect('/auth-login', '/auth/login')->name('login');
Route::redirect('/auth-done', '/')->name('dashboard');

Route::get('/fcea', function () {
    $catchDomain = config('fcea.domain');
    $protocol = str_contains($catchDomain, 'localhost') ? 'http' : 'https';

    return redirect($protocol.'://'.$catchDomain);
});

Route::get('/catch-em-all', function () {
    $catchDomain = config('fcea.domain');
    $protocol = str_contains($catchDomain, 'localhost') ? 'http' : 'https';

    return redirect($protocol.'://'.$catchDomain);
});

Route::middleware(\App\Http\Middleware\EventEndedMiddleware::class)->group(function () {
    Route::prefix('/auth')->name('auth.')->group(function () {
        Route::get('/login', [\App\Http\Controllers\AuthController::class, 'show'])->middleware('guest')->name('login');
        Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login'])->middleware('guest')->name('login.redirect');
        Route::get('/callback', [\App\Http\Controllers\AuthController::class, 'loginCallback'])->middleware('guest')->name('login.callback');
        Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth')->name('logout');
        Route::get('/frontchannel-logout', [\App\Http\Controllers\AuthController::class, 'logoutCallback'])->name('logout.callback');
    });

    Route::middleware(['auth', 'ensure-event-user'])->group(function () {
        Route::resource('badges', \App\Http\Controllers\BadgeController::class);
        Route::post('/badges/refresh-prepaid', [\App\Http\Controllers\BadgeController::class, 'refreshPrepaidBadges'])
            ->name('badges.refresh-prepaid')
            ->middleware('throttle:3,1'); // 3 requests per minute per user
        Route::get('/statistics', [\App\Http\Controllers\StatisticsController::class, 'index'])->name('statistics');
    });
});

// Admin badge PDF routes (used by Filament)
Route::middleware(['auth'])->prefix('admin')->group(function () {
    Route::get('/badge-pdf/{customId}/view', [\App\Http\Controllers\Admin\BadgePdfController::class, 'view'])
        ->name('admin.badge-pdf.view');
    Route::get('/badge-pdf/{customId}/download', [\App\Http\Controllers\Admin\BadgePdfController::class, 'download'])
        ->name('admin.badge-pdf.download');
});

// TEMPORARY local-only sign-in, for driving the admin panel in a browser
// without going through OIDC. Guarded by the environment and by a signed URL,
// and removed as soon as it has been used.
if (app()->environment('local')) {
    Route::get('/dev-login/{user}', function (\App\Models\User $user) {
        auth()->login($user);

        return redirect('/admin');
    })->middleware('signed')->name('dev.login');

    // POS needs three guards, not one: the web user plus a machine and the
    // staff member operating it.
    Route::get('/dev-login-pos/{user}/{machine}/{staff}', function (\App\Models\User $user, \App\Models\Machine $machine, \App\Models\Staff $staff) {
        auth()->login($user);
        auth()->guard('machine')->login($machine);
        // The machine-user guard is backed by Staff, not User.
        auth()->guard('machine-user')->login($staff);

        return redirect('/pos');
    })->middleware('signed')->name('dev.login.pos');
}
