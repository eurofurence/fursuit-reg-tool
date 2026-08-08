<?php

namespace App\Http\Middleware;

use App\Support\Manage\EventScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seeds the global event filter for the /admin group.
 *
 * The instance is bound into the container so the controllers, the shared Inertia prop
 * and the table queries all read the same resolved event once per request.
 *
 * Successor to App\Http\Middleware\the old event-selector middleware, deleted with the old panel.
 * The session keys differ on purpose: the two panels ran side by side for the whole
 * migration and were not allowed to fight over one selection.
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
