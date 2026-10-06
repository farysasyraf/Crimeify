<?php

namespace Farysasyraf\SavedRoutes;

use Farysasyraf\SavedRoutes\Contracts\RoleProvider;
use Farysasyraf\SavedRoutes\Contracts\RouteRecord;
use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use InvalidArgumentException;

/**
 * The way in for an app's own code: add the saved routes again, tell them apart from the app's routes, and reach
 * the controller lookup and the role provider the config sets up.
 */
final class SavedRoutes
{
    /**
     * The methods a saved route can answer, one per route. ANY answers every method.
     */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'ANY'];

    /**
     * The key in a registered route's action that holds the saved route's key, marking it as saved.
     */
    public const ACTION = 'saved_route';

    /**
     * The key in a registered route's action that holds the roles that can open it, as key => name.
     */
    public const ROLES_ACTION = 'saved_route_roles';

    /**
     * The middleware alias that checks those roles.
     */
    public const ROLES_MIDDLEWARE = 'saved-route-roles';

    /**
     * The gate that opens the admin page.
     */
    public const GATE = 'manage-saved-routes';

    /**
     * Add every saved route to the router again, as the database has them now. The package does this once the app
     * has booted; call it again after the table changes within a request, as tests do once their database exists.
     */
    public static function register(): void
    {
        app(RouteRegistrar::class)->register();
    }

    /**
     * Whether a route in the router is one of the saved routes, rather than one from the app's routes files.
     */
    public static function isSaved(Route $route): bool
    {
        return $route->getAction(self::ACTION) !== null;
    }

    /**
     * The model that stores the saved routes.
     *
     * @return class-string<RouteRecord>
     */
    public static function model(): string
    {
        $model = config('saved-routes.model') ?: SavedRoute::class;

        if (! is_subclass_of($model, RouteRecord::class)) {
            throw new InvalidArgumentException('saved-routes.model must implement '.RouteRecord::class.", and {$model} doesn't.");
        }

        return $model;
    }

    /**
     * Whether the saved routes are in the package's own table, which its migration and admin page are for.
     */
    public static function usesOwnModel(): bool
    {
        return is_a(self::model(), SavedRoute::class, true);
    }

    /**
     * Where a saved route's controller can live, and the functions it can call.
     */
    public static function controllers(): ControllerLocator
    {
        return app(ControllerLocator::class);
    }

    /**
     * The roles a saved route can be limited to, or null when the app hasn't set a provider.
     */
    public static function roles(): ?RoleProvider
    {
        $provider = config('saved-routes.roles.provider');

        return $provider ? app($provider) : null;
    }

    /**
     * The HTTP verbs a saved route's method answers. Laravel adds HEAD to every GET route itself.
     *
     * @return list<string>
     */
    public static function verbs(string $method): array
    {
        return $method === 'ANY' ? Router::$verbs : [$method];
    }

    /**
     * Drop the cached saved routes, so the next request reads them from the database.
     */
    public static function forgetCache(): void
    {
        app(RouteRegistrar::class)->forgetCache();
    }
}
