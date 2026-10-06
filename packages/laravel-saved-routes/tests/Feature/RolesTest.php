<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Role;
use Farysasyraf\SavedRoutes\Tests\TestCase;

class RolesTest extends TestCase
{
    public function test_a_route_limited_to_roles_opens_only_for_users_with_one_of_them(): void
    {
        $editor = Role::create(['name' => 'Editor']);
        $auditor = Role::create(['name' => 'Auditor']);
        $this->saveRoute(['path' => 'reports', 'roles' => [(string) $editor->id, (string) $auditor->id]]);

        $this->signIn();
        $this->get('/reports')->assertForbidden()->assertSee('Only users with the Auditor or Editor role can open this page.');

        $this->signIn(['Auditor']);
        $this->get('/reports')->assertOk();
    }

    public function test_it_is_checked_before_the_record_in_the_address_is_looked_up(): void
    {
        $admin = Role::create(['name' => 'admin']);
        $this->saveRoute(['path' => 'users/edit', 'controller' => 'UserController', 'action' => 'edit', 'parameters' => 'user', 'roles' => [(string) $admin->id]]);

        // So someone without the role learns nothing about which records exist.
        $me = $this->signIn();
        $this->get('/users/edit/999')->assertForbidden();
        $this->get("/users/edit/{$me->id}")->assertForbidden();

        $this->signIn(['admin']);
        $this->get('/users/edit/999')->assertNotFound();
        $this->get("/users/edit/{$me->id}")->assertOk()->assertSee('Editing Aminah');
    }

    public function test_a_route_whose_roles_were_all_deleted_opens_for_no_one(): void
    {
        $editor = Role::create(['name' => 'Editor']);
        $this->saveRoute(['path' => 'reports', 'roles' => [(string) $editor->id]]);
        $this->signIn(['Editor']);
        $editor->delete();
        SavedRoutes::register();

        $this->get('/reports')->assertForbidden()->assertSee('No one can open this page: the roles it was limited to have been deleted.');
    }

    public function test_without_a_role_provider_a_limited_route_opens_for_no_one(): void
    {
        $editor = Role::create(['name' => 'Editor']);
        $this->saveRoute(['path' => 'reports', 'roles' => [(string) $editor->id]]);
        $this->signIn(['Editor']);

        config(['saved-routes.roles.provider' => null]);
        SavedRoutes::register();

        $this->get('/reports')->assertForbidden();
    }

    public function test_the_eloquent_provider_lists_roles_by_name(): void
    {
        $b = Role::create(['name' => 'Bravo']);
        $a = Role::create(['name' => 'Alpha']);

        $this->assertSame([$a->id => 'Alpha', $b->id => 'Bravo'], SavedRoutes::roles()->roles());
    }
}
