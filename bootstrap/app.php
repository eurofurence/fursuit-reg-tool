<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::prefix('catch-em-all/')
                ->name('catch-em-all.')
                ->middleware([
                    'web',
                    'catch-auth:web',
                ])
                ->group(base_path('routes/catch-em-all.php'));
            \Illuminate\Support\Facades\Route::middleware([
                'pos-auth:machine',
                'pos-auth:machine-user',
                'web', \App\Http\Middleware\InactivityLogoutMiddleware::class,
            ])
                ->prefix('pos/')
                ->name('pos.')
                ->group(base_path('routes/pos.php'));
            \Illuminate\Support\Facades\Route::prefix('pos/auth/')
                ->name('pos.auth.')
                ->middleware('web')
                ->group(base_path('routes/pos-auth.php'));
            \Illuminate\Support\Facades\Route::prefix('gallery')
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
            'ensure-event-user' => \App\Http\Middleware\EnsureEventUserMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
