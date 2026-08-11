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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            // whole admin.* name prefix; the old panel that sat at
            // /admin-legacy is gone. admin.badge-pdf.*, the last routes registered
            // outside this group, moved into routes/manage/tools.php with their
            // names and URLs intact.
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

            // No Route::fallback() here on purpose. A catch-all would match every
            // unclaimed URI, which also swallows the router's method check: a GET
            // against a POST-only route would answer 404 instead of 405. Misses are
            // left to the router and picked up by the respond() callback below.
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

        // Error pages. Inertia has no error view of its own, so whatever the
        // framework renders out of resources/views/errors drops the visitor out
        // of the SPA shell mid-session: a 404 inside the POS would hand a
        // touchscreen a bare blade page with no way back. Every status listed
        // below is re-rendered through Pages/Error.vue instead, which keeps the
        // right shell (public site, POS, admin, Catch-Em-All) around the message.
        //
        // Debug builds are deliberately left alone - a developer wants the stack
        // trace, not a friendly 500. Set APP_DEBUG=false to see what a visitor sees.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            // JSON clients (the REST API and the print agent) get their normal
            // machine-readable body; an HTML page would break them.
            if ($request->expectsJson() || $request->is('api/*')) {
                return $response;
            }

            if (config('app.debug')) {
                return $response;
            }

            // Maintenance mode aborts in global middleware, so it never reaches
            // the session or Inertia at all. Those requests fall through to
            // resources/views/errors/503.blade.php, which is self-contained and is
            // also what `artisan down --render=errors::503` prerenders.
            if ($status === 503 && ! $request->hasSession()) {
                return $response;
            }

            // 419 is a stale CSRF token, not a wall to bounce off. Send the
            // visitor back where they were with a flash, so the form can be
            // resubmitted against a fresh token.
            if ($status === 419) {
                return back()->with('error', 'Your session expired. Please try again.');
            }

            $handled = [401, 403, 404, 405, 408, 410, 413, 429];

            if ($status < 500 && ! in_array($status, $handled, true)) {
                return $response;
            }

            $host = $request->getHost();
            $context = match (true) {
                // A URI nothing claimed (the usual 404) never enters the web group,
                // so there is no session and Inertia has no shared props: no auth
                // user, no event, no nav. The page is told to drop the site chrome
                // rather than render a header with nothing behind it. Adding a
                // Route::fallback() to get the middleware to run was tried and
                // reverted - it also swallows the router's 405 method check.
                ! $request->hasSession() => 'bare',
                $host === config('fcea.domain') => 'catch',
                $request->is('pos', 'pos/*') => 'pos',
                $request->is('admin', 'admin/*') => 'admin',
                $request->is('gallery', 'gallery/*') => 'gallery',
                default => 'public',
            };

            // Only these statuses carry their message through, and only when the
            // exception is an HTTP one. Everything else keeps the wording baked
            // into Error.vue, because the message on the rest is written by the
            // framework, not for a visitor: a route miss says "The route foo could
            // not be found", a missing model says "No query results for model
            // [App\Models\Badge] 1", and a 5xx says whatever the driver threw.
            $speaksForItself = [401, 403, 410, 413, 429];

            $message = in_array($status, $speaksForItself, true) && $exception instanceof HttpExceptionInterface
                ? trim($exception->getMessage())
                : '';

            return Inertia::render('Error', [
                'status' => $status,
                'message' => $message !== '' ? $message : null,
                'context' => $context,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();
