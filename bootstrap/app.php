<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
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

            // Print agent API. Stateless bearer-token auth: the agent is a
            // native Windows app on the convention LAN, not a browser, so it
            // gets the api middleware group rather than web/session.
            // Deliberately not domain-scoped. The agent is a desktop app that
            // connects to whatever URL it was configured with, which need not be
            // the hostname the POS is served on: a tunnel, an internal address,
            // or a different domain entirely. Scoping this to APP_URL's host made
            // the API silently fall through to the web routes and answer a
            // redirect to the login page instead of JSON.
            \Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/print-agent.php'));

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
        $middleware->validateCsrfTokens(except: [
            'pos/*',
            'pos/auth/machine/status/update',
            'pos/auth/machine/status',
            'pos/auth/printer**',
            'pos/printers/**',
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
