<?php

namespace Farysasyraf\SavedRoutes\Http\Middleware;

use Closure;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a saved route that's limited to roles through only for users with one of them. The registrar gives such a
 * route this middleware and its roles, as key => name. It runs before route model binding, so someone without the
 * role gets "Forbidden" whether or not the record in the address exists, rather than learning which ones do.
 */
class AuthorizeSavedRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $roles = $request->route()?->getAction(SavedRoutes::ROLES_ACTION);

        if ($roles === null) {
            return $next($request);
        }

        if ($roles === []) {
            abort(403, 'No one can open this page: the roles it was limited to have been deleted.');
        }

        $user = $request->user();
        $provider = SavedRoutes::roles();

        if ($user === null || $provider === null || ! $provider->userHasAny($user, array_keys($roles))) {
            abort(403, 'Only users with the '.implode(' or ', $roles).' role can open this page.');
        }

        return $next($request);
    }
}
