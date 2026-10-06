<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\Roles\SpatieRoleProvider;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Spatie\User;
use Farysasyraf\SavedRoutes\Tests\TestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

/**
 * An app that uses spatie/laravel-permission for its roles.
 */
class SpatieRoleProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set([
            'auth.providers.users.model' => User::class,
            'saved-routes.roles' => ['provider' => SpatieRoleProvider::class],
        ]);
    }

    /**
     * Spatie's own roles table rather than the fixtures'. Spatie ships its tables as a migration for the app to
     * publish, so it runs here as it is.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        (include glob(dirname(__DIR__, 2).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php*')[0])->up();
    }

    public function test_spatie_roles_limit_a_route(): void
    {
        $editor = Role::create(['name' => 'Editor']);
        Role::create(['name' => 'Auditor']);
        $this->assertSame(['Auditor', 'Editor'], array_values(SavedRoutes::roles()->roles()));

        $this->saveRoute(['path' => 'reports', 'roles' => [(string) $editor->id]]);
        $user = User::create(['name' => 'Aminah', 'email' => 'aminah@example.com', 'password' => 'secret']);
        $this->actingAs($user);

        $this->get('/reports')->assertForbidden()->assertSee('Only users with the Editor role can open this page.');

        $user->assignRole('Editor');
        $this->get('/reports')->assertOk();
    }
}
