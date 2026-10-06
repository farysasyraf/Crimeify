<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\AppRoute;
use App\Models\Role;
use App\Models\User;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ADMIN role opens the Routes page, so there's always one, with someone in it.
 */
class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Role $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The only user with ADMIN.
        $this->me = $this->signIn();
        $this->admin = Role::firstWhere('Name', Role::Admin);
    }

    public function test_the_admin_role_cannot_be_deleted(): void
    {
        $this->get("/roles/delete/{$this->admin->Id}")
            ->assertOk()
            ->assertSee("The ADMIN role can't be deleted: it's the role that opens the Routes page.", false)
            ->assertDontSee('Delete role</button>', false);

        $this->delete("/roles/destroy/{$this->admin->Id}")->assertForbidden();
        $this->assertNotNull($this->admin->fresh());

        // Other roles still can be.
        $editor = Role::create(['Name' => 'Editor']);
        $this->get("/roles/delete/{$editor->Id}")->assertSee('Delete role</button>', false);
        $this->delete("/roles/destroy/{$editor->Id}")->assertRedirect('/roles');
        $this->assertNull($editor->fresh());
    }

    public function test_the_admin_role_keeps_its_name_but_its_description_can_change(): void
    {
        $this->get("/roles/edit/{$this->admin->Id}")
            ->assertSee('value="ADMIN" required maxlength="100" readonly', false)
            ->assertSee("The ADMIN role keeps its name: it's the role that opens the Routes page.", false);

        $this->put("/roles/update/{$this->admin->Id}", ['name' => 'Boss'])
            ->assertSessionHasErrors(['name' => "The ADMIN role keeps its name: it's the role that opens the Routes page."]);
        $this->put("/roles/update/{$this->admin->Id}", ['name' => 'ADMIN', 'description' => 'Runs the app.'])->assertSessionHasNoErrors();

        $this->assertSame(['ADMIN', 'Runs the app.'], [$this->admin->fresh()->Name, $this->admin->fresh()->Description]);
    }

    public function test_the_only_admin_keeps_the_role_until_someone_else_has_it(): void
    {
        $editor = Role::create(['Name' => 'Editor']);

        $this->put("/users/update/{$this->me->Id}", ['section' => 'roles', 'roles' => [$editor->Id]])
            ->assertSessionHasErrors(['roles' => 'Signed In is the only user with the ADMIN role, which opens the Routes page, so they must keep it. Give the role to someone else first.']);
        $this->assertTrue($this->me->fresh()->isAdmin());

        // Adding a role alongside it is fine.
        $this->put("/users/update/{$this->me->Id}", ['section' => 'roles', 'roles' => [$this->admin->Id, $editor->Id]])->assertSessionHasNoErrors();

        // Once someone else has ADMIN, it can be taken away.
        $other = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);
        $other->roles()->attach($this->admin->Id);
        $this->put("/users/update/{$this->me->Id}", ['section' => 'roles', 'roles' => [$editor->Id]])->assertSessionHasNoErrors();
        $this->assertFalse($this->me->fresh()->isAdmin());
    }

    public function test_the_only_admin_cannot_be_deleted_even_by_someone_let_delete_users(): void
    {
        $only = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);
        $only->roles()->attach($this->admin->Id);
        $this->me->roles()->detach();

        // Deleting users opened to everyone, on the Routes page.
        AppRoute::whereIn('Path', ['users/delete', 'users/destroy'])->update(['OpenToEveryone' => true]);
        SavedRoutes::register();

        $this->get("/users/delete/{$only->Id}")
            ->assertSee('Siti Aminah is the only user with the ADMIN role, which opens the Routes page, so they can\'t be deleted.', false)
            ->assertDontSee('Delete user</button>', false);
        $this->delete("/users/destroy/{$only->Id}")->assertForbidden();
        $this->assertNotNull($only->fresh());
    }
}
