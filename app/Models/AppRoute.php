<?php

namespace App\Models;

use App\Models\Concerns\SetsCreatedAt;
use Farysasyraf\SavedRoutes\Concerns\ActsAsSavedRoute;
use Farysasyraf\SavedRoutes\Contracts\RouteRecord;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

// A route added on the Routes page, stored in dbo.AppRoutes, ported from the AMV route page (t3012menu rows
// with f3012category 'route'): a route name that is also its path, the method it answers, the controller
// function that handles it, parameter names, and who can open it: everyone who is logged in, or only users with
// chosen roles (dbo.AppRouteRoles). The saved-routes package (config/saved-routes.php) adds them to the app on every
// request, and its ActsAsSavedRoute gives each its address, handler and checks.
// The controller lives in app/Http/Controllers or in a module's Http/Controllers, so a route can only point to the
// app's own code.
#[Table(name: 'AppRoutes', key: 'Id', timestamps: false)]
#[Fillable(['MenuItemId', 'Path', 'Parameters', 'Controller', 'Action', 'HttpMethods', 'OpenToEveryone'])]
final class AppRoute extends Model implements RouteRecord
{
    use ActsAsSavedRoute;
    use SetsCreatedAt;

    /**
     * The method a route can answer, one per route: GET opens a page, POST adds from a form, PUT saves changes to a
     * record and DELETE deletes one (forms send them with @method('PUT') or @method('DELETE')). ANY answers every method.
     */
    public const Methods = ['GET', 'POST', 'PUT', 'DELETE', 'ANY'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'MenuItemId' => 'integer',
            'OpenToEveryone' => 'boolean',
            'CreatedAt' => 'datetime',
        ];
    }

    /**
     * The menu link this route is grouped under. Null once that link is deleted.
     *
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'MenuItemId');
    }

    /**
     * The roles that can open this route when it isn't open to everyone, linked through dbo.AppRouteRoles.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'AppRouteRoles', 'AppRouteId', 'RoleId');
    }

    /**
     * Whether only users with some roles can open it. A route whose roles have all been deleted opens for no one.
     */
    public function isLimitedToRoles(): bool
    {
        // Saved before routes had roles, a route is open to everyone.
        return $this->OpenToEveryone === false;
    }

    /**
     * Every saved route with its roles, for the package to add to the app.
     */
    public static function savedRoutes(): iterable
    {
        try {
            return self::with('roles:Id,Name')->orderBy('Id')->get();
        } catch (QueryException) {
            // Routes saved before they had roles, until migrate adds dbo.AppRouteRoles. Without the table itself, the
            // exception goes on to the package, which adds none.
            return self::orderBy('Id')->get();
        }
    }

    public function savedRouteKey(): int
    {
        return $this->Id;
    }

    public function savedRoutePath(): string
    {
        return (string) $this->Path;
    }

    public function savedRouteParameters(): ?string
    {
        return $this->Parameters;
    }

    public function savedRouteController(): string
    {
        return (string) $this->Controller;
    }

    public function savedRouteAction(): string
    {
        return (string) $this->Action;
    }

    public function savedRouteMethod(): string
    {
        return (string) $this->HttpMethods;
    }

    /**
     * The roles chosen on the Routes page, as Id => name, or null when it's open to everyone.
     */
    public function savedRouteRoles(): ?array
    {
        return $this->isLimitedToRoles() ? $this->roles->pluck('Name', 'Id')->all() : null;
    }

    /**
     * The pages a menu link can point to: the app's own pages, then the routes saved on the Routes page.
     * Only addresses that open as they are count: they answer GET, need a login, and take no required parameters.
     *
     * @return array{builtIn: list<string>, saved: list<string>}
     */
    public static function linkablePages(): array
    {
        $builtIn = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => ! SavedRoutes::isSaved($route)
                && in_array('GET', $route->methods())
                && in_array('auth', $route->middleware())
                && ! preg_match('/\{\w+\}/', $route->uri()))
            // Optional parameters can simply be left off.
            ->map(fn ($route) => '/'.trim(preg_replace('~/?\{\w+\?\}~', '', $route->uri()), '/'))
            ->unique()
            ->sort()
            ->values();

        // A plain collection, since the addresses below aren't models.
        $saved = self::orderBy('Path')->get()->toBase()
            ->filter(fn (AppRoute $route) => $route->canOpen())
            ->map(fn (AppRoute $route) => '/'.$route->Path)
            ->diff($builtIn)
            ->unique()
            ->values();

        return ['builtIn' => $builtIn->all(), 'saved' => $saved->all()];
    }
}
