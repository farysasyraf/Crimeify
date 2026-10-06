<?php

use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Farysasyraf\SavedRoutes\Roles\SpatieRoleProvider;
use Spatie\Permission\Models\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | Where saved routes are stored
    |--------------------------------------------------------------------------
    |
    | The model that holds them. The package's own SavedRoute uses the
    | saved_routes table that its migration creates. An app that already
    | keeps its routes in a table of its own can point this at its model
    | instead: it implements Contracts\RouteRecord (see the README), and
    | the package then neither creates its table nor shows its admin page.
    |
    */

    'model' => SavedRoute::class,

    'table' => 'saved_routes',

    /*
    |--------------------------------------------------------------------------
    | Adding them to the app
    |--------------------------------------------------------------------------
    |
    | Saved routes are added once the app has booted, after every route in
    | your routes files, so they can never replace or catch one of those.
    | Each one is added inside these middleware: by default a session and a
    | login, like the pages behind your login.
    |
    */

    'register' => true,

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Controllers a route can point to
    |--------------------------------------------------------------------------
    |
    | A saved route can only call a public function written in one of these
    | folders, on a class built on the base class, so the admin page can't
    | point an address at Laravel's own code or anything else in vendor/.
    | The key is how the admin page names a folder's controllers: '' for
    | plain names like ReportController, or a prefix, like Admin\Report-
    | Controller for 'Admin'. With modules on and nwidart/laravel-modules
    | installed, each enabled module's Http/Controllers is added under its
    | name too, like Setup\RoleController.
    |
    */

    'controllers' => [
        'folders' => [
            '' => [
                'namespace' => 'App\\Http\\Controllers\\',
                'path' => app_path('Http/Controllers'),
            ],
        ],

        'base_class' => 'App\\Http\\Controllers\\Controller',

        'modules' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | Each saved route is open to everyone who is logged in, or only to users
    | with roles ticked on the admin page. The provider lists the roles and
    | checks a user's. Use SpatieRoleProvider with spatie/laravel-permission,
    | EloquentRoleProvider with a Role model of your own (set its options
    | below), or a class of your own implementing Contracts\RoleProvider.
    | With null, every saved route is open to everyone logged in.
    |
    */

    'roles' => [
        'provider' => class_exists(Role::class) ? SpatieRoleProvider::class : null,

        // For EloquentRoleProvider: your Role model, the column shown as its name, and the user's relation to it.
        'model' => null,
        'label' => 'name',
        'relation' => 'roles',
    ],

    /*
    |--------------------------------------------------------------------------
    | The admin page
    |--------------------------------------------------------------------------
    |
    | Where routes are added, changed and deleted. It decides who can open
    | every saved route, so it's locked by default: define the
    | manage-saved-routes gate to say who can use it, for example
    |
    |     Gate::define('manage-saved-routes', fn ($user) => $user->is_admin);
    |
    | The layout is a Blade view the page extends, with the page in the
    | section named below and its <title> in a section named 'title'.
    |
    */

    'admin' => [
        'enabled' => true,
        'path' => 'saved-routes',
        'middleware' => ['web', 'auth', 'can:manage-saved-routes'],
        'layout' => 'saved-routes::layout',
        'section' => 'content',
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Off, every request reads the saved routes from the database. On, they're
    | kept in the cache until one is saved or deleted through its model. A
    | change made straight to the table, like a migration's DB::table()
    | insert, needs `php artisan saved-routes:clear` afterwards.
    |
    */

    'cache' => [
        'enabled' => env('SAVED_ROUTES_CACHE', false),
        'store' => null,
        'key' => 'saved-routes',
    ],

];
