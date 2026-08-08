<?php

use App\Http\Middleware\CatchEmAllAuthMiddleware;
use App\Http\Middleware\CatchEmAllIntroductionMiddleware;
use App\Http\Middleware\EnsureEventUserMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InactivityLogoutMiddleware;
use App\Http\Middleware\ManageEventScope;
use App\Http\Middleware\PosAuthMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;
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
            Route::domain(config('fcea.domain'))
                ->name('catch-em-all.')
                ->middleware([
                    'web',
                ])
                ->group(base_path('routes/catch-em-all.php'));

            // Main application routes (domain-based)
            Route::domain($mainDomain)
                ->middleware('web')
                ->group(base_path('routes/web.php'));

            // The admin panel: Inertia, and the only one. It owns /admin and the
            // whole admin.* name prefix; the Filament panel that sat at
            // /admin-legacy is gone. admin.badge-pdf.*, the last routes registered
            // outside this group, moved into routes/manage/tools.php with their
            // names and URLs intact. See docs/admin/rebuild-plan.md part 5 step 14.
            Route::domain($mainDomain)
                ->middleware([
                    'web',
                    'auth',
                    'can:access-manage',
                    ManageEventScope::class,
                ])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/manage.php'));

            // POS system routes
            Route::domain($mainDomain)
                ->middleware([
                    'pos-auth:machine',
                    'pos-auth:machine-user',
                    'web', InactivityLogoutMiddleware::class,
                ])
                ->prefix('pos/')
                ->name('pos.')
                ->group(base_path('routes/pos.php'));

            // POS authentication routes
            Route::domain($mainDomain)
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
            Route::middleware('api')
                ->group(base_path('routes/print-agent.php'));

            // Gallery routes
            Route::domain($mainDomain)
                ->prefix('gallery')
                ->name('gallery.')
                ->middleware('web')
                ->group(base_path('routes/gallery.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'pos/*',
            'pos/auth/machine/status/update',
            'pos/auth/machine/status',
            'pos/auth/printer**',
            'pos/printers/**',
        ]);
        $middleware->alias([
            'pos-auth' => PosAuthMiddleware::class,
            'catch-auth' => CatchEmAllAuthMiddleware::class,
            'catch-introduction' => CatchEmAllIntroductionMiddleware::class,
            'ensure-event-user' => EnsureEventUserMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
