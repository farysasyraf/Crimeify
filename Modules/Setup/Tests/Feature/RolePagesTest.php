<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function test_roles_page_is_linked_from_the_left_menu(): void
    {
        // The ADMIN role the migrations add, for the Crime data page, so there are none.
        Role::query()->delete();

        $this->get('/users')->assertSee('href="'.url('/roles').'"', false);

        $this->get('/roles')
            ->assertOk()
            ->assertSee($this->sideLink('Roles', 'page'), false)
            ->assertSee('No roles yet.');
    }

    public function test_guests_cannot_manage_roles(): void
    {
        auth()->logout();

        $this->get('/roles')->assertRedirect('/login');
        $this->post('/roles/store', ['name' => 'Sneaky'])->assertRedirect('/login');

        $this->assertDatabaseMissing('Roles', ['Name' => 'Sneaky']);
    }

    public function test_index_lists_roles_by_name_with_user_counts(): void
    {
        $editor = Role::create(['Name' => 'Editor', 'Description' => 'Can edit content']);
        Role::create(['Name' => 'Auditor']);
        $editor->users()->attach([
            User::create(['Name' => 'Ada', 'Email' => 'ada@example.com'])->Id,
            User::create(['Name' => 'Alan', 'Email' => 'alan@example.com'])->Id,
        ]);

        $this->get('/roles')
            ->assertOk()
            ->assertSeeInOrder(['Auditor', '<td class="num">0</td>', 'Editor', 'Can edit content', '<td class="num">2</td>'], false);
    }

    public function test_create_adds_role(): void
    {
        $this->get('/roles/create')->assertOk()->assertSee('Add role');

        $this->post('/roles/store', ['name' => '  Editor ', 'description' => 'Can edit content'])
            ->assertRedirect('/roles')
            ->assertSessionHas('message', 'Added the Editor role.');

        $this->assertDatabaseHas('Roles', ['Name' => 'Editor', 'Description' => 'Can edit content']);
    }

    public function test_create_validates_input(): void
    {
        Role::create(['Name' => 'Editor']);
        $roles = Role::count();

        $this->post('/roles/store', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/roles/store', ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');
        $this->post('/roles/store', ['name' => 'Editor'])
            ->assertSessionHasErrors(['name' => "A role named 'Editor' already exists."]);

        $this->assertSame($roles, Role::count());
    }

    public function test_edit_updates_role_and_can_keep_its_own_name(): void
    {
        $role = Role::create(['Name' => 'Editor']);

        $this->get("/roles/edit/{$role->Id}")->assertOk()->assertSee('value="Editor"', false);

        $this->put("/roles/update/{$role->Id}", ['name' => 'Editor', 'description' => 'Can edit content'])
            ->assertRedirect('/roles')
            ->assertSessionHas('message', 'Saved changes to Editor.');

        $this->assertDatabaseHas('Roles', ['Id' => $role->Id, 'Description' => 'Can edit content']);
    }

    public function test_edit_rejects_another_roles_name(): void
    {
        Role::create(['Name' => 'Auditor']);
        $editor = Role::create(['Name' => 'Editor']);

        $this->put("/roles/update/{$editor->Id}", ['name' => 'Auditor'])->assertSessionHasErrors('name');

        $this->assertDatabaseHas('Roles', ['Id' => $editor->Id, 'Name' => 'Editor']);
    }

    public function test_deleting_a_role_takes_it_away_from_users_but_keeps_the_users(): void
    {
        $role = Role::create(['Name' => 'Editor']);
        $ada = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);
        $role->users()->attach($ada->Id);

        $this->get("/roles/delete/{$role->Id}")
            ->assertOk()
            ->assertSee('1 user has this role and will lose it.');

        $this->delete("/roles/destroy/{$role->Id}")
            ->assertRedirect('/roles')
            ->assertSessionHas('message', 'Deleted the Editor role.');

        $this->assertDatabaseMissing('Roles', ['Id' => $role->Id]);
        $this->assertDatabaseMissing('UserRoles', ['RoleId' => $role->Id]);
        $this->assertDatabaseHas('Users', ['Id' => $ada->Id]);
    }

    public function test_missing_role_returns_not_found(): void
    {
        $this->get('/roles/edit/999')->assertNotFound();
        $this->get('/roles/delete/999')->assertNotFound();
        $this->delete('/roles/destroy/999')->assertNotFound();
    }
}
