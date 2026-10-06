<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MenuItemPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->signIn();
    }

    /**
     * Give the signed-in user these roles, as they'd be loaded on their next request.
     */
    private function giveMeRoles(Role ...$roles): void
    {
        // Along with ADMIN, which the add, edit and delete routes are limited to; no menu link here is.
        $admin = Role::firstWhere('Name', 'ADMIN');
        $this->me->roles()->sync([$admin->Id, ...array_map(fn (Role $role) => $role->Id, $roles)]);
        $this->me->unsetRelation('roles');
    }

    public function test_links_limited_to_roles_are_only_shown_to_users_with_one_of_those_roles(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $editor = Role::create(['Name' => 'Editor']);
        $viewer = Role::create(['Name' => 'Viewer']);

        $this->post('/menu-items/store', [
            'label' => 'Reports', 'url' => '/reports', 'sort_order' => 5,
            'level' => 1, 'visible_to' => 'roles', 'roles' => [$admin->Id, $editor->Id],
        ])->assertRedirect('/menu-items');

        // No roles: only the links visible to everyone.
        $this->get('/users')->assertSee('>Users</span></a>', false)->assertDontSee('>Reports</span></a>', false);

        $this->giveMeRoles($viewer);
        $this->get('/users')->assertDontSee('>Reports</span></a>', false);

        $this->giveMeRoles($editor);
        $this->get('/users')->assertSee('>Reports</span></a>', false)->assertSee('>Users</span></a>', false);

        $this->giveMeRoles($viewer, $admin);
        $this->get('/users')->assertSee('>Reports</span></a>', false);
    }

    public function test_limiting_a_link_to_roles_requires_at_least_one_role(): void
    {
        Role::create(['Name' => 'Admin']);

        $this->post('/menu-items/store', ['label' => 'Reports', 'url' => '/reports', 'sort_order' => 5, 'level' => 1, 'visible_to' => 'roles'])
            ->assertSessionHasErrors(['roles' => 'Tick at least one role, or choose "Everyone who is logged in".']);

        $this->post('/menu-items/store', ['label' => 'Reports', 'url' => '/reports', 'sort_order' => 5])
            ->assertSessionHasErrors('visible_to');

        $this->post('/menu-items/store', ['label' => 'Reports', 'url' => '/reports', 'sort_order' => 5, 'level' => 1, 'visible_to' => 'roles', 'roles' => [999]])
            ->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('MenuItems', ['Label' => 'Reports']);
    }

    public function test_edit_form_shows_the_current_visibility_and_everyone_clears_the_roles(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $item = MenuItem::create(['Label' => 'Reports', 'Url' => '/reports', 'SortOrder' => 5, 'VisibleToEveryone' => false]);
        $item->roles()->attach($admin->Id);

        $this->get("/menu-items/edit/{$item->Id}")
            ->assertSee('value="roles" checked', false)
            ->assertSee('value="'.$admin->Id.'" checked', false);

        $this->put("/menu-items/update/{$item->Id}", [
            'label' => 'Reports', 'url' => '/reports', 'sort_order' => 5,
            'level' => 1, 'visible_to' => 'everyone', 'roles' => [$admin->Id],
        ])->assertRedirect('/menu-items');

        $item->refresh();
        $this->assertTrue($item->VisibleToEveryone);
        $this->assertCount(0, $item->roles);
        $this->get('/users')->assertSee('>Reports</span></a>', false);
    }

    public function test_index_shows_who_can_see_each_link(): void
    {
        $item = MenuItem::create(['Label' => 'Reports', 'Url' => '/reports', 'SortOrder' => 5, 'VisibleToEveryone' => false]);
        $item->roles()->attach([Role::create(['Name' => 'Editor'])->Id, Role::create(['Name' => 'Admin'])->Id]);

        $this->get('/menu-items')->assertSeeInOrder([
            '<code>/users</code>', 'Everyone',
            '<code>/reports</code>', '<span class="badge badge-role">Admin</span>', '<span class="badge badge-role">Editor</span>',
        ], false);
    }

    public function test_deleting_the_only_role_of_a_link_hides_it_from_everyone(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $item = MenuItem::create(['Label' => 'Reports', 'Url' => '/reports', 'SortOrder' => 5, 'VisibleToEveryone' => false]);
        $item->roles()->attach($admin->Id);
        $this->giveMeRoles($admin);

        $this->get('/users')->assertSee('>Reports</span></a>', false);

        $this->get("/roles/delete/{$admin->Id}")->assertSee('1 menu link is shown to this role.');
        $this->delete("/roles/destroy/{$admin->Id}");
        $this->me->unsetRelation('roles');

        $this->get('/users')->assertDontSee('>Reports</span></a>', false);
        $this->get('/menu-items')->assertSee('No one');
    }

    public function test_sidebar_starts_with_the_default_links_and_marks_the_current_page(): void
    {
        $this->get('/users')
            ->assertOk()
            ->assertSeeInOrder([
                $this->sideLink('Users', 'page'),
                $this->sideLink('Add user'),
                $this->sideLink('Roles'),
                'href="'.url('/menu-items').'" class="side-link" '.$this->sideLink('Manage menu'),
                'href="'.url('/routes').'" class="side-link" '.$this->sideLink('Manage routes'),
            ], false);

        $this->get('/users/create')
            ->assertSee($this->sideLink('Users'), false)
            ->assertSee($this->sideLink('Add user', 'page'), false);
    }

    public function test_links_stay_highlighted_on_their_sub_pages_except_home(): void
    {
        $this->get('/menu-items/create')
            ->assertSee('class="side-link active" '.$this->sideLink('Manage menu', 'true'), false)
            ->assertSee($this->sideLink('Users'), false);

        $item = MenuItem::where('Label', 'Roles')->firstOrFail();
        $this->get("/menu-items/edit/{$item->Id}")->assertSee($this->sideLink('Manage menu', 'true'), false);

        // Editing a user is under Users (/users); "Add user" is /users/create, which isn't a parent of /users/edit/5.
        $this->get('/users/edit/'.User::first()->Id)
            ->assertSee($this->sideLink('Users', 'true'), false)
            ->assertSee($this->sideLink('Add user'), false);

        // A page with a link of its own is marked by that link alone, not also by the one it's under.
        $this->get('/users/create')
            ->assertSee($this->sideLink('Add user', 'page'), false)
            ->assertSee($this->sideLink('Users'), false);
    }

    public function test_manage_menu_link_can_be_limited_to_a_role_like_any_other_link(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $item = MenuItem::where('Url', '/menu-items')->firstOrFail();

        $this->put("/menu-items/update/{$item->Id}", [
            'label' => 'Manage menu', 'url' => '/menu-items', 'sort_order' => $item->SortOrder,
            'level' => 1, 'visible_to' => 'roles', 'roles' => [$admin->Id],
        ])->assertRedirect('/menu-items');

        $this->get('/users')->assertDontSee('>Manage menu</span></a>', false);

        $this->giveMeRoles($admin);
        $this->get('/users')->assertSee('>Manage menu</span></a>', false);
    }

    public function test_deleting_the_manage_menu_link_warns_first(): void
    {
        $manage = MenuItem::where('Url', '/menu-items')->firstOrFail();
        $roles = MenuItem::where('Url', '/roles')->firstOrFail();

        $this->get("/menu-items/delete/{$manage->Id}")->assertSee('This is the link to this Manage menu page.');
        $this->get("/menu-items/delete/{$roles->Id}")->assertDontSee('This is the link to this Manage menu page.');
    }

    public function test_pages_still_render_when_the_menu_table_is_missing(): void
    {
        Schema::drop('MenuItems');

        $this->get('/users/create')
            ->assertOk()
            ->assertSee('No menu items yet.');
    }

    public function test_index_lists_items_in_menu_order(): void
    {
        $this->get('/menu-items')
            ->assertOk()
            ->assertSee('Navigation menu')
            ->assertSeeInOrder(['<code>/users</code>', '<code>/users/create</code>'], false);
    }

    public function test_create_suggests_the_next_position(): void
    {
        $this->get('/menu-items/create')
            ->assertOk()
            // After the starter links, Crime data (7) and the Map Module heading (8).
            ->assertSee('name="sort_order" value="9"', false);
    }

    public function test_create_adds_item_to_the_sidebar(): void
    {
        $this->post('/menu-items/store', ['label' => 'Laravel docs', 'url' => 'https://laravel.com/docs', 'sort_order' => 3, 'level' => 1, 'visible_to' => 'everyone'])
            ->assertRedirect('/menu-items')
            ->assertSessionHas('message', 'Added Laravel docs to the menu.');

        $this->assertDatabaseHas('MenuItems', ['Label' => 'Laravel docs', 'Url' => 'https://laravel.com/docs', 'SortOrder' => 3]);

        $this->get('/users')->assertSeeInOrder([
            '>Users</span></a>',
            '>Add user</span></a>',
            'href="https://laravel.com/docs"',
        ], false);
    }

    public function test_link_list_offers_the_pages_that_open_as_they_are(): void
    {
        $reports = MenuItem::create(['Label' => 'Reports', 'SortOrder' => 9]);

        foreach ([['reports/monthly', null, 'GET'], ['everything', null, 'ANY'], ['people', 'user', 'GET'], ['reports/export', null, 'POST']] as [$path, $parameters, $method]) {
            AppRoute::create([
                'MenuItemId' => $reports->Id, 'Path' => $path, 'Parameters' => $parameters,
                'Controller' => 'Setup\RoleController', 'Action' => 'index', 'HttpMethods' => $method,
            ]);
        }

        $this->get('/menu-items/create')
            ->assertSeeInOrder([
                '<option value="" selected>None · a heading that groups the links under it</option>',
                // What stays in the code: the start page and the Routes page.
                '<optgroup label="Pages in this app">',
                '<option value="/" >/</option>',
                '<option value="/routes" >/routes</option>',
                // The app's pages the migrations save on the Routes page, and routes saved there since.
                '<optgroup label="Routes from Manage routes">',
                '<option value="/crime-data" >/crime-data</option>',
                '<option value="/dashboard" >/dashboard</option>',
                '<option value="/everything" >/everything</option>',
                '<option value="/menu-items" >/menu-items</option>',
                '<option value="/menu-items/create" >/menu-items/create</option>',
                '<option value="/reports/monthly" >/reports/monthly</option>',
                '<option value="/roles" >/roles</option>',
                '<option value="/users" >/users</option>',
                '<option value="/users/create" >/users/create</option>',
                '<option value="other" >Other address…</option>',
            ], false)
            // Guest pages, and routes that need a parameter or don't answer GET, can't be opened from the menu.
            ->assertDontSee('value="/login"', false)
            ->assertDontSee('value="/up"', false)
            ->assertDontSee('value="/people"', false)
            ->assertDontSee('value="/reports/export"', false);
    }

    public function test_other_address_saves_the_address_typed_in_the_box(): void
    {
        $fields = ['label' => 'Docs', 'url' => 'other', 'sort_order' => 3, 'level' => 1, 'visible_to' => 'everyone'];

        $this->post('/menu-items/store', $fields + ['url_other' => ''])
            ->assertSessionHasErrors(['url_other' => 'Type the address, or choose a page from the list.']);
        $this->post('/menu-items/store', $fields + ['url_other' => 'javascript:alert(1)'])->assertSessionHasErrors('url_other');
        $this->assertDatabaseMissing('MenuItems', ['Label' => 'Docs']);

        $this->post('/menu-items/store', $fields + ['url_other' => 'https://laravel.com/docs'])->assertRedirect('/menu-items');
        $this->assertDatabaseHas('MenuItems', ['Label' => 'Docs', 'Url' => 'https://laravel.com/docs']);

        // The box is ignored unless Other address is chosen.
        $this->post('/menu-items/store', ['label' => 'Roles again', 'url' => '/roles', 'url_other' => 'https://example.com'] + $fields)
            ->assertRedirect('/menu-items');
        $this->assertDatabaseHas('MenuItems', ['Label' => 'Roles again', 'Url' => '/roles']);
    }

    public function test_edit_form_picks_the_links_page_or_fills_in_other_address(): void
    {
        $addUser = MenuItem::where('Label', 'Add user')->firstOrFail();
        $docs = MenuItem::create(['Label' => 'Docs', 'Url' => 'https://laravel.com/docs', 'SortOrder' => 9]);

        $this->get("/menu-items/edit/{$addUser->Id}")
            ->assertSee('<option value="/users/create" selected>/users/create</option>', false)
            ->assertSee('<option value="other" >Other address…</option>', false)
            ->assertSee('name="url_other" value=""', false);

        $this->get("/menu-items/edit/{$docs->Id}")
            ->assertSee('<option value="other" selected>Other address…</option>', false)
            ->assertSee('name="url_other" value="https://laravel.com/docs"', false);

        // After a failed save, Other address stays chosen with what was typed.
        $this->from("/menu-items/edit/{$docs->Id}")
            ->put("/menu-items/update/{$docs->Id}", ['label' => 'Docs', 'url' => 'other', 'url_other' => 'docs', 'sort_order' => 9, 'level' => 1, 'visible_to' => 'everyone'])
            ->assertSessionHasErrors('url_other');

        $this->get("/menu-items/edit/{$docs->Id}")
            ->assertSee('<option value="other" selected>Other address…</option>', false)
            ->assertSee('name="url_other" value="docs"', false);
    }

    public function test_icons_are_saved_and_shown_at_every_level(): void
    {
        $fields = ['label' => 'Setup', 'url' => '', 'sort_order' => 7, 'level' => 1, 'visible_to' => 'everyone'];

        $this->post('/menu-items/store', $fields + ['icon' => 'Bad Icon!'])
            ->assertSessionHasErrors(['icon' => 'Use a Material icon name: lowercase letters, numbers and _, like account_balance.']);

        $this->post('/menu-items/store', $fields + ['icon' => 'account_balance'])->assertRedirect('/menu-items');
        $setup = MenuItem::where('Label', 'Setup')->firstOrFail();

        // A level 2 link has an icon of its own too.
        $this->post('/menu-items/store', [
            'label' => 'Banks', 'url' => '/roles', 'sort_order' => 1, 'level' => 2,
            'parent_for_level_2' => $setup->Id, 'visible_to' => 'everyone', 'icon' => 'savings',
        ])->assertRedirect('/menu-items');

        $banks = MenuItem::where('Label', 'Banks')->firstOrFail();
        $this->assertSame(['account_balance', 'savings'], [$setup->Icon, $banks->Icon]);

        $this->get("/menu-items/edit/{$banks->Id}")
            ->assertSee('data-icon-preview>savings</span>', false)
            ->assertSee('name="icon" value="savings"', false)
            ->assertDontSee('name="short_label"', false);

        // The list shows each link's icon, whatever its level.
        $this->get('/menu-items')->assertSeeInOrder([
            '<span class="material-icon tree-icon" aria-hidden="true">account_balance</span>',
            '<span class="tree-mark" aria-hidden="true">└</span>',
            '<span class="material-icon tree-icon" aria-hidden="true">savings</span>',
        ], false);

        $this->get('/users')->assertSee($this->sideLink('Banks', icon: 'savings'), false);
    }

    public function test_create_validates_input(): void
    {
        $this->post('/menu-items/store', ['label' => '', 'url' => 'javascript:alert(1)', 'sort_order' => -1])
            ->assertSessionHasErrors(['label', 'url', 'sort_order']);

        $this->post('/menu-items/store', ['label' => 'Reports', 'url' => 'reports', 'sort_order' => 'first'])
            ->assertSessionHasErrors(['url', 'sort_order']);

        // Only the links the migrations add.
        $this->assertDatabaseCount('MenuItems', 10);
    }

    public function test_edit_updates_item_and_reorders_the_sidebar(): void
    {
        $item = MenuItem::where('Label', 'Add user')->firstOrFail();

        $this->get("/menu-items/edit/{$item->Id}")->assertOk()->assertSee('value="/users/create"', false);

        $this->put("/menu-items/update/{$item->Id}", ['label' => 'New user', 'url' => '/users/create', 'sort_order' => 0, 'level' => 1, 'visible_to' => 'everyone'])
            ->assertRedirect('/menu-items')
            ->assertSessionHas('message', 'Saved changes to New user.');

        $this->get('/users')->assertSeeInOrder(['>New user</span></a>', '>Users</span></a>'], false);
    }

    public function test_delete_asks_for_confirmation_then_removes_item(): void
    {
        $item = MenuItem::where('Label', 'Add user')->firstOrFail();

        $this->get("/menu-items/delete/{$item->Id}")->assertOk()->assertSee('Delete menu item');

        $this->delete("/menu-items/destroy/{$item->Id}")
            ->assertRedirect('/menu-items')
            ->assertSessionHas('message', 'Removed Add user from the menu.');

        $this->assertDatabaseMissing('MenuItems', ['Id' => $item->Id]);
        $this->get('/users')->assertDontSee('>Add user</span></a>', false);
    }

    public function test_manage_menu_page_is_still_reachable_by_address_when_the_menu_is_empty(): void
    {
        MenuItem::query()->delete();

        $this->get('/users')
            ->assertSee('No menu items yet.')
            ->assertDontSee('Manage menu');

        $this->get('/menu-items')->assertOk()->assertSee('The menu is empty.');
    }

    public function test_missing_item_returns_not_found(): void
    {
        $this->get('/menu-items/edit/999')->assertNotFound();
        $this->get('/menu-items/delete/999')->assertNotFound();
        $this->delete('/menu-items/destroy/999')->assertNotFound();
    }
}
