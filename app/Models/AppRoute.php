<?php

namespace App\Models;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;
use ReflectionClass;
use ReflectionMethod;

// A route added on the Routes page, stored in dbo.AppRoutes, ported from the AMV route page (t3012menu rows
// with f3012category 'route'): a route name that is also its path, the method it answers, the controller
// function that handles it, parameter names, and who can open it: everyone who is logged in, or only users with
// chosen roles (dbo.AppRouteRoles). AppServiceProvider adds them to the app on every request.
// The controller lives in app/Http/Controllers or in a module's Http/Controllers, so a route can only point to the
// app's own code.
#[Table(name: 'AppRoutes', key: 'Id', timestamps: false)]
#[Fillable(['MenuItemId', 'Path', 'Parameters', 'Controller', 'Action', 'HttpMethods', 'OpenToEveryone'])]
class AppRoute extends Model
{
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
     * The address as Laravel registers it: the path, then a placeholder for each parameter, like reports/{id}/{kw}.
     */
    public function uri(): string
    {
        $placeholders = collect(explode('/', (string) $this->Parameters))
            ->filter()
            ->map(fn (string $name) => '{'.$name.'}');

        return collect([$this->Path])->concat($placeholders)->implode('/');
    }

    /**
     * The address as people see it, like /reports/{id}.
     */
    public function address(): string
    {
        return '/'.$this->uri();
    }

    /**
     * The method and address together, like GET /reports/{id}.
     */
    public function label(): string
    {
        return $this->HttpMethods.' '.$this->address();
    }

    /**
     * The controller function that handles it, like ReportController@monthly.
     */
    public function handler(): string
    {
        return $this->Controller.'@'.$this->Action;
    }

    /**
     * The HTTP verbs the route answers. Laravel adds HEAD to every GET route itself.
     *
     * @return list<string>
     */
    public function verbs(): array
    {
        return $this->HttpMethods === 'ANY' ? Router::$verbs : [$this->HttpMethods];
    }

    /**
     * Whether the address opens in a browser as it is: it answers GET and every parameter is optional.
     */
    public function canOpen(): bool
    {
        return in_array('GET', $this->verbs())
            && collect(explode('/', (string) $this->Parameters))->filter()->every(fn (string $name) => str_ends_with($name, '?'));
    }

    /**
     * Whether the controller or function it points to has gone from the code, so opening it fails.
     */
    public function isBroken(): bool
    {
        $controller = static::findController($this->controllerClass());

        return $controller === null || static::findAction($controller, $this->Action) === null;
    }

    /**
     * Whether the search term appears in the address or the controller function.
     */
    public function matches(string $term): bool
    {
        return Str::contains($this->address().' '.$this->handler(), $term, ignoreCase: true);
    }

    /**
     * Describe what already answers this route's address for its method, or already has its name,
     * or return null when neither is taken. Placeholder names don't matter: /reports/{id} and /reports/{month}
     * are the same address.
     */
    public function clash(): ?string
    {
        $address = static::comparable($this->uri());

        foreach (Route::getRoutes() as $route) {
            // Saved routes are compared from the database below, which may be newer than what this request loaded.
            if ($route->getAction('app_route') !== null) {
                continue;
            }

            $shared = static::comparable($route->uri()) === $address
                ? array_diff(array_intersect($this->verbs(), $route->methods()), ['HEAD'])
                : [];

            if ($shared !== []) {
                return implode('/', $shared)." {$this->address()} is already a page in this app. Choose another route name or method.";
            }

            if ($route->getName() === $this->Path) {
                return "{$this->Path} is already the name of a page in this app. Choose another route name.";
            }
        }

        $others = static::query()->when($this->exists, fn ($query) => $query->whereKeyNot($this->Id))->get();

        foreach ($others as $other) {
            $shared = static::comparable($other->uri()) === $address
                ? array_diff(array_intersect($this->verbs(), $other->verbs()), ['HEAD'])
                : [];

            if ($shared !== []) {
                return implode('/', $shared)." {$other->address()} is already a route, handled by {$other->handler()}. Choose another route name or method.";
            }
        }

        return null;
    }

    /**
     * Add every saved route behind the login, like every page. AppServiceProvider calls this once the app has booted,
     * after routes/web.php and every module's routes, so a saved route can never catch a page's address.
     */
    public static function registerBehindLogin(): void
    {
        static::forgetRegistered();
        Route::middleware(['web', 'auth'])->group(fn () => static::registerAll());

        // So route('reports/monthly') finds the new names.
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /**
     * Take out the saved routes already added, so adding them again follows dbo.AppRoutes as it is now, changed
     * addresses and roles included. On a request there are none yet; it matters when adding them again later,
     * as tests do after changing a route.
     */
    private static function forgetRegistered(): void
    {
        $routes = Route::getRoutes()->getRoutes();

        if (! collect($routes)->contains(fn ($route) => $route->getAction('app_route') !== null)) {
            return;
        }

        $kept = new RouteCollection;
        foreach ($routes as $route) {
            if ($route->getAction('app_route') === null) {
                $kept->add($route);
            }
        }

        // The URL generator follows the new collection too, so route() does.
        Route::setRoutes($kept);
    }

    /**
     * Add every saved route to the router, inside whatever group calls this, named after its path as in AMV,
     * so code can link to it with route('reports/monthly').
     * A saved route never replaces a page already registered for the same address and method, or takes its name.
     */
    public static function registerAll(): void
    {
        try {
            $saved = static::with('roles:Id,Name')->orderBy('Id')->get();
        } catch (QueryException) {
            try {
                // Routes saved before they had roles, until migrate adds dbo.AppRouteRoles.
                $saved = static::orderBy('Id')->get();
            } catch (QueryException) {
                // No database yet, or migrate hasn't created dbo.AppRoutes. The users list reports database problems.
                return;
            }
        }

        $takenNames = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->flip();

        // Fixed addresses first, so /reports/summary isn't caught by an earlier /reports/{id}.
        [$fixed, $withParameters] = $saved->partition(fn (AppRoute $route) => blank($route->Parameters));

        foreach ($fixed->concat($withParameters) as $route) {
            $registered = Route::getRoutes()->getRoutesByMethod();

            if (collect($route->verbs())->contains(fn (string $verb) => isset($registered[$verb][$route->uri()]))) {
                continue;
            }

            $added = Route::match($route->verbs(), $route->uri(), [
                'uses' => $route->controllerClass().'@'.$route->Action,
                'app_route' => $route->Id,
            ]);

            // Only for the roles chosen on the Routes page: EnsureUserCanOpenRoute checks them, by Id => name.
            if ($route->isLimitedToRoles()) {
                $added->setAction($added->getAction() + ['app_route_roles' => $route->roles->pluck('Name', 'Id')->all()])
                    ->middleware('route-roles');
            }

            // Routes that share a path (GET and POST, say) share its name, so only the first one takes it.
            if (! $takenNames->has($route->Path)) {
                $added->name($route->Path);
                $takenNames->put($route->Path, true);
            }
        }
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
            ->filter(fn ($route) => $route->getAction('app_route') === null
                && in_array('GET', $route->methods())
                && in_array('auth', $route->middleware())
                && ! preg_match('/\{\w+\}/', $route->uri()))
            // Optional parameters can simply be left off.
            ->map(fn ($route) => '/'.trim(preg_replace('~/?\{\w+\?\}~', '', $route->uri()), '/'))
            ->unique()
            ->sort()
            ->values();

        // A plain collection, since the addresses below aren't models.
        $saved = static::orderBy('Path')->get()->toBase()
            ->filter(fn (AppRoute $route) => $route->canOpen())
            ->map(fn (AppRoute $route) => '/'.$route->Path)
            ->diff($builtIn)
            ->unique()
            ->values();

        return ['builtIn' => $builtIn->all(), 'saved' => $saved->all()];
    }

    /**
     * Tidy what was typed in Parameter into names joined by /, as AMV stores f3012parameter:
     * "id, kw" or "{id}/{kw}" both become id/kw. A name ending in ? is optional.
     * Returns null when a name isn't valid, is used twice, or a required one follows an optional one.
     */
    public static function parseParameters(string $typed): ?string
    {
        $names = [];
        $seen = [];
        $optionalSeen = false;

        foreach (preg_split('~[\s,/]+~', trim($typed), flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $part = preg_replace('/^\{(.*)\}$/', '$1', $part);

            // Up to 32 characters, which is as long as Laravel's router allows.
            if (! preg_match('/^([A-Za-z_]\w{0,31})(\?)?$/', $part, $match)) {
                return null;
            }

            $optional = isset($match[2]);

            if (in_array(strtolower($match[1]), $seen) || ($optionalSeen && ! $optional)) {
                return null;
            }

            $seen[] = strtolower($match[1]);
            $optionalSeen = $optional;
            $names[] = $match[1].($optional ? '?' : '');
        }

        return $names === [] ? null : implode('/', $names);
    }

    /**
     * Where a route's controller can live, by the prefix that names it on the Routes page: nothing for
     * app/Http/Controllers, and a module's name for that module's Http/Controllers, as in AMV's
     * Modules/Setup/Http/Controllers. Only enabled modules count.
     *
     * @return array<string, array{namespace: string, path: string}>
     */
    public static function controllerFolders(): array
    {
        return static::$controllerFolders ??= static::findControllerFolders();
    }

    /**
     * The controller folders, looked up once per request since every saved route needs them.
     *
     * @var ?array<string, array{namespace: string, path: string}>
     */
    private static ?array $controllerFolders = null;

    /**
     * @return array<string, array{namespace: string, path: string}>
     */
    private static function findControllerFolders(): array
    {
        $folders = ['' => ['namespace' => 'App\\Http\\Controllers\\', 'path' => app_path('Http/Controllers')]];
        $inModule = config('modules.paths.generator.controller.path', 'Http/Controllers');

        foreach (Module::allEnabled() as $module) {
            $folders[$module->getName()] = [
                'namespace' => config('modules.namespace', 'Modules').'\\'.$module->getName().'\\'.str_replace('/', '\\', $inModule).'\\',
                'path' => module_path($module->getName(), $inModule),
            ];
        }

        return $folders;
    }

    /**
     * Find the controllers a name typed on the Routes page could mean: one in app/Http/Controllers, like
     * LoginController or Admin\ReportController; one in a module, like Setup\RoleController; or just RoleController,
     * in whichever folder has it, as AMV's routes name the controller alone. A full class name works too.
     * Only classes built on the app's base Controller count.
     *
     * @return list<ReflectionClass<Controller>>
     */
    public static function findControllers(string $name): array
    {
        $name = Str::of($name)->trim()->replace('/', '\\')->ltrim('\\')->value();
        $folders = static::controllerFolders();
        $module = Str::before($name, '\\');

        $candidates = match (true) {
            // A full class name, like Modules\Setup\Http\Controllers\RoleController.
            collect($folders)->contains(fn (array $folder) => str_starts_with($name, $folder['namespace'])) => collect($folders)
                ->filter(fn (array $folder) => str_starts_with($name, $folder['namespace']))
                ->map(fn (array $folder) => [$folder['namespace'], Str::after($name, $folder['namespace'])]),
            // A module's name first, like Setup\RoleController.
            $module !== '' && $module !== $name && isset($folders[$module]) => collect([[$folders[$module]['namespace'], Str::after($name, '\\')]]),
            default => collect($folders)->map(fn (array $folder) => [$folder['namespace'], $name]),
        };

        return $candidates
            // Letters, numbers and _ only, so the name can't point outside a controllers folder.
            ->filter(fn (array $candidate) => preg_match('/^[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)*$/', $candidate[1]) && class_exists($candidate[0].$candidate[1]))
            ->map(fn (array $candidate) => new ReflectionClass($candidate[0].$candidate[1]))
            ->filter(fn (ReflectionClass $controller) => $controller->isSubclassOf(Controller::class) && $controller->isInstantiable())
            ->unique(fn (ReflectionClass $controller) => strtolower($controller->getName()))
            ->values()
            ->all();
    }

    /**
     * The one controller a name means, or null when there's none, or more than one.
     *
     * @return ?ReflectionClass<Controller>
     */
    public static function findController(string $name): ?ReflectionClass
    {
        $found = static::findControllers($name);

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * How the Routes page names a controller: its class within app/Http/Controllers, like LoginController,
     * or within a module's Http/Controllers after the module's name, like Setup\RoleController.
     */
    public static function controllerName(ReflectionClass $controller): string
    {
        foreach (static::controllerFolders() as $prefix => $folder) {
            if (str_starts_with($controller->getName(), $folder['namespace'])) {
                $inFolder = Str::after($controller->getName(), $folder['namespace']);

                return $prefix === '' ? $inFolder : $prefix.'\\'.$inFolder;
            }
        }

        return $controller->getName();
    }

    /**
     * The full class name of the controller the route points to, like Modules\Setup\Http\Controllers\RoleController.
     */
    public function controllerClass(): string
    {
        $folders = static::controllerFolders();
        $module = Str::before((string) $this->Controller, '\\');

        return $module !== $this->Controller && isset($folders[$module]) && $module !== ''
            ? $folders[$module]['namespace'].Str::after($this->Controller, '\\')
            : $folders['']['namespace'].$this->Controller;
    }

    /**
     * Find a function a route can call: public, not static, not a PHP magic method,
     * and written in the app or one of its modules rather than inherited from Laravel.
     */
    public static function findAction(ReflectionClass $controller, string $name): ?ReflectionMethod
    {
        $name = trim($name);

        if (! preg_match('/^[A-Za-z_]\w*$/', $name) || str_starts_with($name, '__') || ! $controller->hasMethod($name)) {
            return null;
        }

        $action = $controller->getMethod($name);
        $writtenBy = $action->getDeclaringClass()->getName();

        return $action->isPublic() && ! $action->isStatic() && ! $action->isAbstract()
            && (str_starts_with($writtenBy, 'App\\') || str_starts_with($writtenBy, config('modules.namespace', 'Modules').'\\'))
            ? $action
            : null;
    }

    /**
     * The controllers a route can point to, in the app and each enabled module, each with the functions
     * it can call, for suggestions on the form.
     *
     * @return array<string, list<string>>
     */
    public static function controllerOptions(): array
    {
        $options = [];

        foreach (static::controllerFolders() as $folder) {
            if (! is_dir($folder['path'])) {
                continue;
            }

            foreach (File::allFiles($folder['path']) as $file) {
                $controller = $file->getExtension() === 'php'
                    ? static::findController($folder['namespace'].str_replace('/', '\\', Str::chopEnd($file->getRelativePathname(), '.php')))
                    : null;

                if ($controller !== null) {
                    $options[static::controllerName($controller)] = collect($controller->getMethods())
                        ->filter(fn (ReflectionMethod $action) => static::findAction($controller, $action->getName()) !== null)
                        ->map(fn (ReflectionMethod $action) => $action->getName())
                        ->values()
                        ->all();
                }
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * An address in a form that ignores placeholder names and letter case, for spotting clashes.
     */
    private static function comparable(string $uri): string
    {
        return strtolower(preg_replace(['/\{\w+\?\}/', '/\{\w+\}/'], ['{?}', '{}'], trim($uri, '/')));
    }
}
