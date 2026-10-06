<?php

namespace Farysasyraf\SavedRoutes;

use Farysasyraf\SavedRoutes\Contracts\RouteRecord;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Adds the saved routes to the router, after every route in the app's routes files, so a saved route never
 * replaces or catches one of those, and checks a route before it's saved for an address or name already taken.
 */
class RouteRegistrar
{
    /**
     * Add every saved route inside the saved routes' middleware, taking out any added before, so the router
     * follows the table as it is now, changed addresses and roles included.
     */
    public function register(): void
    {
        $this->forget();
        Route::middleware(config('saved-routes.middleware', ['web', 'auth']))->group(fn () => $this->registerAll());

        // So route('reports/monthly') finds the new names.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /**
     * Add every saved route to the router, inside whatever group calls this, each named after its path. A saved
     * route never replaces a route already registered for the same address and method, or takes its name.
     */
    public function registerAll(): void
    {
        $takenNames = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->flip();

        foreach ($this->definitions() as $route) {
            $registered = Route::getRoutes()->getRoutesByMethod();

            if (collect($route['verbs'])->contains(fn (string $verb) => isset($registered[$verb][$route['uri']]))) {
                continue;
            }

            $added = Route::match($route['verbs'], $route['uri'], [
                'uses' => $route['uses'],
                SavedRoutes::ACTION => $route['key'],
            ]);

            // Only for the roles chosen: the saved-route-roles middleware checks them, by key => name.
            if ($route['roles'] !== null) {
                $added->setAction($added->getAction() + [SavedRoutes::ROLES_ACTION => $route['roles']])
                    ->middleware(SavedRoutes::ROLES_MIDDLEWARE);
            }

            // Routes that share a path (GET and POST, say) share its name, so only the first one takes it.
            if (! $takenNames->has($route['name'])) {
                $added->name($route['name']);
                $takenNames->put($route['name'], true);
            }
        }
    }

    /**
     * Take out the saved routes already added. On a request there are none yet; it matters when adding them again
     * later in the same request, as tests do after changing a route.
     */
    public function forget(): void
    {
        $routes = Route::getRoutes()->getRoutes();

        if (! collect($routes)->contains(fn ($route) => SavedRoutes::isSaved($route))) {
            return;
        }

        $kept = new RouteCollection;
        foreach ($routes as $route) {
            if (! SavedRoutes::isSaved($route)) {
                $kept->add($route);
            }
        }

        // The URL generator follows the new collection too, so route() does.
        Route::setRoutes($kept);
    }

    /**
     * Every saved route as the router needs it, fixed addresses first, so /reports/summary isn't caught by an
     * earlier /reports/{id}. None when the table isn't there yet, as before the first migrate.
     *
     * @return list<array{key: int|string, name: string, uri: string, verbs: list<string>, uses: string, roles: ?array<int|string, string>}>
     */
    public function definitions(): array
    {
        try {
            if (! config('saved-routes.cache.enabled')) {
                return $this->load();
            }

            return $this->cache()->rememberForever(config('saved-routes.cache.key', 'saved-routes'), fn () => $this->load());
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Drop the cached saved routes, so the next request reads them from the database.
     */
    public function forgetCache(): void
    {
        if (config('saved-routes.cache.enabled')) {
            $this->cache()->forget(config('saved-routes.cache.key', 'saved-routes'));
        }
    }

    /**
     * @return list<array{key: int|string, name: string, uri: string, verbs: list<string>, uses: string, roles: ?array<int|string, string>}>
     */
    private function load(): array
    {
        $model = SavedRoutes::model();
        $saved = collect($model::savedRoutes());

        [$fixed, $withParameters] = $saved->partition(fn (RouteRecord $route) => blank($route->savedRouteParameters()));

        return $fixed->concat($withParameters)->map(fn (RouteRecord $route) => [
            'key' => $route->savedRouteKey(),
            'name' => $route->savedRoutePath(),
            'uri' => Parameters::uri($route->savedRoutePath(), $route->savedRouteParameters()),
            'verbs' => SavedRoutes::verbs($route->savedRouteMethod()),
            'uses' => SavedRoutes::controllers()->classFor($route->savedRouteController()).'@'.$route->savedRouteAction(),
            'roles' => $route->savedRouteRoles(),
        ])->values()->all();
    }

    private function cache(): Repository
    {
        return Cache::store(config('saved-routes.cache.store'));
    }

    /**
     * Describe what already answers a route's address for its method, or already has its name, or return null when
     * neither is taken. Placeholder names don't matter: /reports/{id} and /reports/{month} are the same address.
     *
     * @param  iterable<RouteRecord>  $others  the other saved routes, read from the database, which may be newer
     *                                         than what this request added to the router
     */
    public function clash(RouteRecord $route, iterable $others): ?string
    {
        $uri = Parameters::uri($route->savedRoutePath(), $route->savedRouteParameters());
        $address = self::comparable($uri);
        $verbs = SavedRoutes::verbs($route->savedRouteMethod());

        foreach (Route::getRoutes() as $registered) {
            if (SavedRoutes::isSaved($registered)) {
                continue;
            }

            $shared = self::comparable($registered->uri()) === $address
                ? array_diff(array_intersect($verbs, $registered->methods()), ['HEAD'])
                : [];

            if ($shared !== []) {
                return implode('/', $shared)." /{$uri} is already a page in this app. Choose another route name or method.";
            }

            if ($registered->getName() === $route->savedRoutePath()) {
                return "{$route->savedRoutePath()} is already the name of a page in this app. Choose another route name.";
            }
        }

        foreach ($others as $other) {
            $otherUri = Parameters::uri($other->savedRoutePath(), $other->savedRouteParameters());
            $shared = self::comparable($otherUri) === $address
                ? array_diff(array_intersect($verbs, SavedRoutes::verbs($other->savedRouteMethod())), ['HEAD'])
                : [];

            if ($shared !== []) {
                return implode('/', $shared)." /{$otherUri} is already a route, handled by {$other->savedRouteController()}@{$other->savedRouteAction()}. Choose another route name or method.";
            }
        }

        return null;
    }

    /**
     * An address in a form that ignores placeholder names and letter case, for spotting clashes.
     */
    private static function comparable(string $uri): string
    {
        return strtolower(preg_replace(['/\{\w+\?\}/', '/\{\w+\}/'], ['{?}', '{}'], trim($uri, '/')));
    }
}
