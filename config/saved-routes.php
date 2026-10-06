<?php

use App\Http\Controllers\Controller;
use App\Models\AppRoute;
use App\Models\Role;
use Farysasyraf\SavedRoutes\Roles\EloquentRoleProvider;

// farysasyraf/laravel-saved-routes (packages/laravel-saved-routes) adds the routes saved on the Routes page to the
// app once it has booted. Crimeify keeps them in its own dbo.AppRoutes, edited on its own Routes page (the Setup
// module's AppRouteController), so the package's table and admin page are off.
return [

    'model' => AppRoute::class,

    'register' => true,

    // Like every page: a session and a login.
    'middleware' => ['web', 'auth'],

    // app/Http/Controllers, and each enabled module's Http/Controllers under the module's name, like
    // Setup\RoleController. Only classes built on the app's base Controller.
    'controllers' => [
        'folders' => [
            '' => ['namespace' => 'App\\Http\\Controllers\\', 'path' => app_path('Http/Controllers')],
        ],
        'base_class' => Controller::class,
        'modules' => true,
    ],

    // dbo.Roles, given to users through dbo.UserRoles, chosen per route on the Routes page (dbo.AppRouteRoles).
    'roles' => [
        'provider' => EloquentRoleProvider::class,
        'model' => Role::class,
        'label' => 'Name',
        'relation' => 'roles',
    ],

    'admin' => [
        'enabled' => false,
    ],

    // Off: the migrations add and change routes with DB::table(), which a cache wouldn't see.
    'cache' => [
        'enabled' => false,
    ],

];
