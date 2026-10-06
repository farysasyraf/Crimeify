<?php

namespace Farysasyraf\SavedRoutes\Models;

use Farysasyraf\SavedRoutes\Concerns\ActsAsSavedRoute;
use Farysasyraf\SavedRoutes\Contracts\RouteRecord;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Database\Eloquent\Model;

/**
 * A route added on the admin page, stored in the saved_routes table: the path that is also its name, its
 * parameters, the method it answers, the controller function that handles it, and who can open it, as the keys
 * of the roles it's limited to, or null for everyone.
 *
 * @property int $id
 * @property string $path
 * @property ?string $parameters
 * @property string $controller
 * @property string $action
 * @property string $method
 * @property ?list<int|string> $roles
 */
class SavedRoute extends Model implements RouteRecord
{
    use ActsAsSavedRoute;

    protected $fillable = ['path', 'parameters', 'controller', 'action', 'method', 'roles'];

    /**
     * The roles as key => name, read once for every route savedRoutes() loads.
     *
     * @var ?array<int|string, string>
     */
    private ?array $roleNames = null;

    public function getTable(): string
    {
        return config('saved-routes.table', 'saved_routes');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['roles' => 'array'];
    }

    public static function savedRoutes(): iterable
    {
        $routes = static::query()->orderBy('id')->get();
        $names = $routes->contains(fn (self $route) => $route->roles !== null) ? SavedRoutes::roles()?->roles() : null;

        foreach ($routes as $route) {
            $route->roleNames = $names;
        }

        return $routes;
    }

    public function savedRouteKey(): int|string
    {
        return $this->getKey();
    }

    public function savedRoutePath(): string
    {
        return (string) $this->path;
    }

    public function savedRouteParameters(): ?string
    {
        return $this->parameters;
    }

    public function savedRouteController(): string
    {
        return (string) $this->controller;
    }

    public function savedRouteAction(): string
    {
        return (string) $this->action;
    }

    public function savedRouteMethod(): string
    {
        return (string) $this->method;
    }

    /**
     * The roles it's limited to, by key => name. Roles deleted since are left out, so a route whose roles have all
     * been deleted opens for no one. Without a role provider the keys stand for their names.
     */
    public function savedRouteRoles(): ?array
    {
        if ($this->roles === null) {
            return null;
        }

        $names = $this->roleNames ?? SavedRoutes::roles()?->roles();

        return $names === null
            ? array_combine($this->roles, $this->roles)
            : array_intersect_key($names, array_flip($this->roles));
    }

    /**
     * Whether only users with some roles can open it.
     */
    public function isLimitedToRoles(): bool
    {
        return $this->roles !== null;
    }
}
