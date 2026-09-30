<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MenuLevelsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->signIn();
    }

    /**
     * Submit the menu link form. Fields the test doesn't give keep the link's current values,
     * or for a new link default to a level 1 heading visible to everyone.
     *
     * @param  array<string, mixed>  $fields
     */
    private function saveLink(array $fields, ?MenuItem $item = null): TestResponse
    {
        $defaults = ['url' => '', 'sort_order' => 1, 'level' => 1, 'visible_to' => 'everyone'];

        if ($item !== null) {
            $level = $item->level();
            $defaults = [
                'label' => $item->Label,
                'url' => $item->Url ?? '',
                'sort_order' => $item->SortOrder,
                'level' => $level,
                "parent_for_level_{$level}" => $item->ParentId,
                'visible_to' => $item->VisibleToEveryone ? 'everyone' : 'roles',
                'roles' => $item->roles->modelKeys(),
            ] + $defaults;
        }

        $fields += $defaults;

        return $item === null
            ? $this->post('/menu-items/store', $fields)
            : $this->put("/menu-items/update/{$item->Id}", $fields);
    }

    private function link(string $label): MenuItem
    {
        return MenuItem::where('Label', $label)->firstOrFail();
    }

    /**
     * Settings (a heading) > Roles > Role reports, with Manage menu also under Settings.
     */
    private function buildSettingsGroup(): void
    {
        $this->saveLink(['label' => 'Settings', 'sort_order' => 10])->assertRedirect('/menu-items');
        $settings = $this->link('Settings');

        $this->saveLink(['level' => 2, 'parent_for_level_2' => $settings->Id, 'sort_order' => 1], $this->link('Roles'))
            ->assertRedirect('/menu-items');
        $this->saveLink(['level' => 2, 'parent_for_level_2' => $settings->Id, 'sort_order' => 2], $this->link('Manage menu'))
            ->assertRedirect('/menu-items');
        $this->saveLink(['label' => 'Role reports', 'url' => '/roles/reports', 'level' => 3, 'parent_for_level_3' => $this->link('Roles')->Id])
            ->assertRedirect('/menu-items');
    }

    private function giveMeRoles(Role ...$roles): void
    {
        $this->me->roles()->sync(array_map(fn (Role $role) => $role->Id, $roles));
        $this->me->unsetRelation('roles');
    }

    public function test_level_2_and_3_links_nest_in_the_sidebar(): void
    {
        $this->buildSettingsGroup();

        $this->assertNull($this->link('Settings')->Url);
        $this->assertSame(3, $this->link('Role reports')->level());

        $this->get('/users')->assertSeeInOrder([
            '>Users</span></a>',
            '<span class="side-text">Settings</span><svg class="chevron"',
            '<ul class="side-list side-level-2"',
            '>Roles</span></a>',
            '<ul class="side-list side-level-3"',
            '>Role reports</span></a>',
            '>Manage menu</span></a>',
        ], false);
    }

    public function test_level_2_needs_a_level_1_parent_and_level_3_a_level_2_parent(): void
    {
        $this->buildSettingsGroup();
        $count = MenuItem::count();

        $this->saveLink(['label' => 'X', 'level' => 2])
            ->assertSessionHasErrors(['parent_for_level_2' => 'Choose the level 1 link to put it under.']);
        $this->saveLink(['label' => 'X', 'level' => 3])
            ->assertSessionHasErrors(['parent_for_level_3' => 'Choose the level 2 link to put it under.']);

        // Roles is level 2 and Settings is level 1, so each is the wrong kind of parent here.
        $this->saveLink(['label' => 'X', 'level' => 2, 'parent_for_level_2' => $this->link('Roles')->Id])
            ->assertSessionHasErrors('parent_for_level_2');
        $this->saveLink(['label' => 'X', 'level' => 3, 'parent_for_level_3' => $this->link('Settings')->Id])
            ->assertSessionHasErrors('parent_for_level_3');
        $this->saveLink(['label' => 'X', 'level' => 3, 'parent_for_level_3' => 999])
            ->assertSessionHasErrors('parent_for_level_3');

        $this->saveLink(['label' => 'X', 'level' => 4])->assertSessionHasErrors('level');

        $this->assertSame($count, MenuItem::count());
    }

    public function test_a_link_cannot_be_put_under_itself(): void
    {
        $this->buildSettingsGroup();
        $roles = $this->link('Roles');

        $this->saveLink(['level' => 3, 'parent_for_level_3' => $roles->Id], $roles)
            ->assertSessionHasErrors('parent_for_level_3');

        $this->assertSame($this->link('Settings')->Id, $roles->fresh()->ParentId);
    }

    public function test_links_with_sub_links_cannot_move_so_deep_that_they_pass_level_3(): void
    {
        $this->buildSettingsGroup();
        $users = $this->link('Users');

        // Settings has links two levels under it, so it must stay level 1.
        $this->get('/menu-items/edit/'.$this->link('Settings')->Id)->assertSee('so it can be at most level 1.');
        $this->saveLink(['level' => 2, 'parent_for_level_2' => $users->Id], $this->link('Settings'))
            ->assertSessionHasErrors(['level' => 'This link has sub-links under it, so it can be at most level 1.']);

        // Roles has one level under it, so it can't go to level 3.
        $this->saveLink(['level' => 3, 'parent_for_level_3' => $this->link('Manage menu')->Id], $this->link('Roles'))
            ->assertSessionHasErrors(['level' => 'This link has sub-links under it, so it can be at most level 2.']);

        $this->assertNull($this->link('Settings')->ParentId);
    }

    public function test_moving_a_link_takes_the_links_under_it_along(): void
    {
        $this->saveLink(['label' => 'Settings'])->assertRedirect('/menu-items');
        $settings = $this->link('Settings');
        $this->saveLink(['level' => 2, 'parent_for_level_2' => $settings->Id], $this->link('Roles'))->assertRedirect('/menu-items');

        $this->saveLink(['level' => 2, 'parent_for_level_2' => $this->link('Users')->Id], $settings)->assertRedirect('/menu-items');

        $this->assertSame(3, $this->link('Roles')->level());
        $this->get('/users')->assertSeeInOrder([
            '>Users</span></a>',
            '<ul class="side-list side-level-2"',
            'Settings',
            '<ul class="side-list side-level-3"',
            '>Roles</span></a>',
        ], false);
    }

    public function test_links_under_a_hidden_link_are_hidden_too(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $this->buildSettingsGroup();
        $settings = $this->link('Settings');

        $this->saveLink(['visible_to' => 'roles', 'roles' => [$admin->Id]], $settings)->assertRedirect('/menu-items');

        // Roles itself is visible to everyone, but it sits under Settings.
        $this->get('/users')
            ->assertDontSee('<span class="side-text">Settings</span><svg class="chevron"', false)
            ->assertDontSee('>Roles</span></a>', false)
            ->assertSee('>Users</span></a>', false);

        $this->giveMeRoles($admin);
        $this->get('/users')
            ->assertSee('<span class="side-text">Settings</span><svg class="chevron"', false)
            ->assertSee('>Roles</span></a>', false);
    }

    public function test_headings_with_nothing_visible_under_them_are_hidden(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $this->saveLink(['label' => 'Empty group'])->assertRedirect('/menu-items');
        $this->saveLink(['label' => 'Admin group'])->assertRedirect('/menu-items');
        $this->saveLink([
            'label' => 'Audit log', 'url' => '/audit', 'level' => 2, 'parent_for_level_2' => $this->link('Admin group')->Id,
            'visible_to' => 'roles', 'roles' => [$admin->Id],
        ])->assertRedirect('/menu-items');

        $this->get('/users')
            ->assertDontSee('<span class="side-text">Empty group</span><svg class="chevron"', false)
            ->assertDontSee('<span class="side-text">Admin group</span><svg class="chevron"', false);

        $this->giveMeRoles($admin);
        $this->get('/users')
            ->assertSee('<span class="side-text">Admin group</span><svg class="chevron"', false)
            ->assertSee('>Audit log</span></a>', false)
            ->assertDontSee('<span class="side-text">Empty group</span><svg class="chevron"', false);

        // The Manage menu page still lists every link, headings included.
        $this->get('/menu-items')->assertSee('Empty group')->assertSee('None · heading');
    }

    public function test_manage_menu_page_shows_the_tree_with_levels(): void
    {
        $this->buildSettingsGroup();
        $reports = $this->link('Role reports');

        $this->get('/menu-items')
            ->assertSeeInOrder([
                'tree-level-1', 'Settings', 'None · heading',
                'tree-level-2', 'Roles', '<code>/roles</code>',
                'tree-level-3', 'Role reports',
                'tree-level-2', 'Manage menu',
            ], false)
            // Level 3 is the deepest, so it gets no "+ Sub-link" button.
            ->assertSee(route('menu-items/create', ['parent' => $this->link('Roles')->Id]), false)
            ->assertDontSee(route('menu-items/create', ['parent' => $reports->Id]), false);
    }

    public function test_sub_link_button_opens_the_form_with_the_parent_chosen(): void
    {
        $this->buildSettingsGroup();
        $settings = $this->link('Settings');
        $roles = $this->link('Roles');

        $this->get("/menu-items/create?parent={$settings->Id}")
            ->assertSee('name="level" value="2" checked', false)
            ->assertSee('value="'.$settings->Id.'" selected>Settings</option>', false);

        $this->get("/menu-items/create?parent={$roles->Id}")
            ->assertSee('name="level" value="3" checked', false)
            ->assertSee('value="'.$roles->Id.'" selected>Settings › Roles</option>', false);

        // A level 3 link can't have links under it, so the form falls back to level 1.
        $this->get('/menu-items/create?parent='.$this->link('Role reports')->Id)
            ->assertSee('name="level" value="1" checked', false);
    }

    public function test_edit_form_shows_the_current_level_and_parent(): void
    {
        $this->buildSettingsGroup();
        $settings = $this->link('Settings');
        $roles = $this->link('Roles');

        $this->get("/menu-items/edit/{$roles->Id}")
            ->assertSee('name="level" value="2" checked', false)
            ->assertSee('value="'.$settings->Id.'" selected>Settings</option>', false)
            // A link can't be chosen as its own parent.
            ->assertDontSee('>Settings › Roles</option>', false);
    }

    public function test_deleting_a_link_deletes_the_links_under_it(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $this->buildSettingsGroup();
        $reports = $this->link('Role reports');
        $this->saveLink(['visible_to' => 'roles', 'roles' => [$admin->Id]], $reports)->assertRedirect('/menu-items');
        $settings = $this->link('Settings');

        $this->get("/menu-items/delete/{$settings->Id}")
            ->assertSee('The links under it will be deleted too:')
            ->assertSeeInOrder(['Roles', 'Role reports', 'Manage menu']);

        $this->delete("/menu-items/destroy/{$settings->Id}")
            ->assertRedirect('/menu-items')
            ->assertSessionHas('message', 'Removed Settings and its 3 sub-links from the menu.');

        foreach (['Settings', 'Roles', 'Role reports', 'Manage menu'] as $label) {
            $this->assertDatabaseMissing('MenuItems', ['Label' => $label]);
        }
        $this->assertDatabaseMissing('MenuItemRoles', ['MenuItemId' => $reports->Id]);
        $this->assertDatabaseHas('MenuItems', ['Label' => 'Users']);
    }
}
