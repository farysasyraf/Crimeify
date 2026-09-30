<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Lets a route saved on the Routes page through only for users with one of the roles chosen for it there.
// AppRoute::registerAll() gives such a route this middleware and its roles, as Id => name.
class EnsureUserCanOpenRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $roles = $request->route()?->getAction('app_route_roles');

        if ($roles === null) {
            return $next($request);
        }

        if ($roles === []) {
            abort(403, 'No one can open this page: the roles it was limited to have been deleted.');
        }

        if (! $request->user()?->roles()->whereIn('Roles.Id', array_keys($roles))->exists()) {
            abort(403, 'Only users with the '.implode(' or ', $roles).' role can open this page.');
        }

        return $next($request);
    }
}
