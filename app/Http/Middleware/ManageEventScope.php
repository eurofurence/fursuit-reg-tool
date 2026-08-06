<?php

namespace App\Http\Middleware;

use App\Support\Manage\EventScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seeds the global event filter for the /manage group.
 *
 * The instance is bound into the container so the controllers, the shared Inertia prop
 * and the table queries all read the same resolved event once per request.
 *
 * Not a replacement for App\Http\Middleware\FilamentEventSelector, which keeps running
 * on /admin until Filament is removed. The two use different session keys on purpose:
 * the panels must not fight over one selection while both are live.
 */
class ManageEventScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $scope = new EventScope;
        $scope->seedDefault();

        app()->instance(EventScope::class, $scope);

        return $next($request);
    }
}
