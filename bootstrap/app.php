<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Parse domain from APP_URL
            $mainDomain = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';

            // Catch-Em-All game routes (specific domain - higher priority)
            \Illuminate\Support\Facades\Route::domain(config('fcea.domain'))
                ->name('catch-em-all.')
                ->middleware([
                    'web',
                ])
                ->group(base_path('routes/catch-em-all.php'));

            // Main application routes (domain-based)
            \Illuminate\Support\Facades\Route::domain($mainDomain)
                ->middleware('web')
                ->group(base_path('routes/web.php'));

            // POS system routes
            \Illuminate\Support\Facades\Route::domain($mainDomain)
                ->middleware([
                    'pos-auth:machine',
                    'pos-auth:machine-user',
                    'web', \App\Http\Middleware\InactivityLogoutMiddleware::class,
                ])
                ->prefix('pos/')
                ->name('pos.')
                ->group(base_path('routes/pos.php'));

            // POS authentication routes
            \Illuminate\Support\Facades\Route::domain($mainDomain)
                ->prefix('pos/auth/')
                ->name('pos.auth.')
                ->middleware('web')
                ->group(base_path('routes/pos-auth.php'));

            // Gallery routes
            \Illuminate\Support\Facades\Route::domain($mainDomain)
                ->prefix('gallery')
                ->name('gallery.')
                ->middleware('web')
                ->group(base_path('routes/gallery.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'pos-auth' => \App\Http\Middleware\PosAuthMiddleware::class,
            'catch-auth' => \App\Http\Middleware\CatchEmAllAuthMiddleware::class,
            'catch-introduction' => \App\Http\Middleware\CatchEmAllIntroductionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
