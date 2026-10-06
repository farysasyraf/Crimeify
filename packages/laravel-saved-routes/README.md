# Laravel Saved Routes

Add routes to a Laravel app from a page in the app, without touching `routes/web.php`.

Each saved route is a row in the database: an address, the HTTP method it answers, the controller function that handles it, and who can open it, either everyone who is logged in or only users with roles you tick. The package adds them to the router on every request, after the routes in your code, so a saved route can never replace one of those.

```
GET  /reports/monthly/{month?}   →  ReportController@monthly    open to everyone logged in
PUT  /users/update/{user}        →  Admin\UserController@update  only Admin, Editor
```

It suits apps where an administrator, not a developer, decides which pages exist and who can open each one. Developers still write the controllers: a saved route can only point to a public function in your own controller folders, never at Laravel or anything else in `vendor/`.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require farysasyraf/laravel-saved-routes
php artisan migrate
```

The migration creates a `saved_routes` table. The admin page is at `/saved-routes`, and **no one can open it until you say who can**, because it controls access to every saved route. Define the gate in a service provider:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-saved-routes', fn ($user) => $user->is_admin);
```

To change any setting, publish the config:

```bash
php artisan vendor:publish --tag=saved-routes-config
```

## Roles

Pick where roles come from in `config/saved-routes.php`.

**spatie/laravel-permission.** With spatie installed, it's used automatically. Its roles appear on the admin page, and `hasAnyRole()` checks them.

**Your own `Role` model.** Use `EloquentRoleProvider` and say which model, which column holds the name, and which relation on your user model gives a user's roles:

```php
'roles' => [
    'provider' => \Farysasyraf\SavedRoutes\Roles\EloquentRoleProvider::class,
    'model' => \App\Models\Role::class,
    'label' => 'name',
    'relation' => 'roles',   // $user->roles()
],
```

**Anything else.** Write a class that implements `Farysasyraf\SavedRoutes\Contracts\RoleProvider`, which has two methods: `roles()` lists them as key => name, and `userHasAny($user, $keys)` checks a user.

With `'provider' => null`, every saved route is open to everyone who passes its middleware.

A role is checked before route model binding runs. Someone without the role gets 403 whether or not `/users/edit/5` exists, so they can't learn which records do. If every role a route was limited to is deleted, that route opens for no one rather than for everyone.

## Configuration

| Key | Default | What it does |
|---|---|---|
| `middleware` | `['web', 'auth']` | Middleware every saved route goes through. |
| `controllers.folders` | `app/Http/Controllers` | Folders a route's controller can come from. The key is a prefix used to name a folder's controllers, like `Billing` for `Billing\InvoiceController`. |
| `controllers.base_class` | `App\Http\Controllers\Controller` | Only subclasses of this class count. |
| `controllers.modules` | `true` | With [nwidart/laravel-modules](https://github.com/nWidart/laravel-modules), each enabled module's `Http/Controllers` is added under the module's name. |
| `admin.enabled` / `admin.path` | `true` / `saved-routes` | The admin page and its address. |
| `admin.middleware` | `['web', 'auth', 'can:manage-saved-routes']` | Who can reach the admin page. |
| `admin.layout` / `admin.section` | `saved-routes::layout` / `content` | Put the page in your own Blade layout. It also yields a `title` section. |
| `cache.enabled` | `false` | Cache saved routes instead of reading the table on every request. |

To restyle the admin page, publish its views with `--tag=saved-routes-views`. To change the table first, publish the migration with `--tag=saved-routes-migrations`.

## Linking to saved routes

Each saved route is named after its path, so link to it like any named route:

```php
route('reports/monthly', ['month' => 'june']);
```

When two routes share a path, such as a GET and a POST, the one saved first takes the name.

Saved routes are added after your routes files, so `php artisan route:list` shows them as usual.

## Caching

With `cache.enabled` on, the routes are read once and kept until a route is saved or deleted through its model. A change written straight to the table, such as a `DB::table()` insert in a migration, isn't seen until you run:

```bash
php artisan saved-routes:clear
```

**Route caching:** `php artisan route:cache` includes the saved routes that exist when it runs. Afterwards, changes made on the admin page don't take effect until you run `route:cache` again. The page shows a warning while routes are cached.

## Using a table you already have

If your app already stores routes in its own table, keep it. Implement `Contracts\RouteRecord` on your model and add the `ActsAsSavedRoute` trait:

```php
use Farysasyraf\SavedRoutes\Concerns\ActsAsSavedRoute;
use Farysasyraf\SavedRoutes\Contracts\RouteRecord;

class AppRoute extends Model implements RouteRecord
{
    use ActsAsSavedRoute;

    public static function savedRoutes(): iterable
    {
        return static::with('roles')->orderBy('id')->get();
    }

    public function savedRouteKey(): int { return $this->id; }
    public function savedRoutePath(): string { return $this->path; }             // reports/monthly
    public function savedRouteParameters(): ?string { return $this->params; }    // id/kw?
    public function savedRouteController(): string { return $this->controller; } // ReportController
    public function savedRouteAction(): string { return $this->function; }
    public function savedRouteMethod(): string { return $this->verb; }           // GET, POST, PUT, PATCH, DELETE or ANY

    public function savedRouteRoles(): ?array
    {
        // null: everyone. Otherwise the roles that can open it, as key => name.
        return $this->restricted ? $this->roles->pluck('name', 'id')->all() : null;
    }
}
```

Then set `'model' => App\Models\AppRoute::class` in the config. The package skips its own table and admin page, and still adds your routes with the same checks. The trait gives your model `address()`, `label()`, `handler()`, `canOpen()` and `isBroken()`. Its `clash()` method tells you whether an address or name is already taken, so you can build your own admin page.

Some helpers you can use from your own code:

```php
use Farysasyraf\SavedRoutes\Parameters;
use Farysasyraf\SavedRoutes\SavedRoutes;

SavedRoutes::register();                       // add the saved routes again, e.g. in tests once the database exists
SavedRoutes::isSaved($route);                  // a saved route, or one from your routes files?
SavedRoutes::controllers()->find('ReportController');
SavedRoutes::controllers()->options();         // every controller with the functions a route can call
Parameters::parse('{id}, kw?');                // "id/kw?"
```

## Testing

The app adds saved routes when it boots, which in a test is before the database exists. Call `SavedRoutes::register()` in your test's `setUp()` once the database is ready.

To run the package's own tests:

```bash
composer install
composer test
```

## License

MIT. See [LICENSE](LICENSE).
