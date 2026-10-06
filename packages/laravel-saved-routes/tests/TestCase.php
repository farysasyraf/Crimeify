<?php

namespace Farysasyraf\SavedRoutes\Tests;

use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Farysasyraf\SavedRoutes\Roles\EloquentRoleProvider;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\SavedRoutesServiceProvider;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers\Controller;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Role;
use Farysasyraf\SavedRoutes\Tests\Fixtures\User;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * An app with two controller folders: the app's own, and a Billing one named by its prefix, as a module's would be.
 * Roles come from a Role model of its own, through the user's roles relation.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SavedRoutesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'auth.providers.users.model' => User::class,
            'saved-routes.controllers' => [
                'folders' => [
                    '' => ['namespace' => 'Farysasyraf\\SavedRoutes\\Tests\\Fixtures\\Controllers\\', 'path' => __DIR__.'/Fixtures/Controllers'],
                    'Billing' => ['namespace' => 'Farysasyraf\\SavedRoutes\\Tests\\Fixtures\\Billing\\', 'path' => __DIR__.'/Fixtures/Billing'],
                ],
                'base_class' => Controller::class,
                'modules' => false,
            ],
            'saved-routes.roles' => [
                'provider' => EloquentRoleProvider::class,
                'model' => Role::class,
                'label' => 'name',
                'relation' => 'roles',
            ],
        ]);
    }

    /**
     * A new in-memory database for each test, with the users table, the fixtures' roles and their own routes table,
     * and the package's table unless the test points saved-routes.model at a model of its own.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');

        if (SavedRoutes::usesOwnModel()) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        }
    }

    protected function defineRoutes($router): void
    {
        // Routes in the app's code: a page a saved route can't take over, and the login page guests are sent to.
        Route::middleware('web')->group(function () {
            Route::get('/login', fn () => 'Log in')->name('login');
            Route::get('/settings', fn () => 'Settings in the code')->name('settings');
        });
    }

    /**
     * Log in as a new user with these roles.
     *
     * @param  list<string>  $roles
     */
    protected function signIn(array $roles = []): User
    {
        $user = User::create(['name' => 'Aminah', 'email' => 'aminah'.User::count().'@example.com', 'password' => 'secret']);
        $user->roles()->attach(array_map(fn (string $name) => Role::firstOrCreate(['name' => $name])->id, $roles));
        $this->actingAs($user);

        return $user;
    }

    /**
     * Save a route straight to the table and add the saved routes to the router again, as the next request would.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function saveRoute(array $attributes): SavedRoute
    {
        $route = SavedRoute::create($attributes + ['controller' => 'ReportController', 'action' => 'index', 'method' => 'GET']);
        SavedRoutes::register();

        return $route;
    }
}
