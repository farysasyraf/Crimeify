<?php

namespace Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->signIn();
    }

    /**
     * The attributes that make confirm.js ask before the form is sent.
     */
    private function asks(string $question, string $kind): string
    {
        return 'data-confirm="'.e($question).'" data-confirm-kind="'.$kind.'"';
    }

    public function test_every_page_loads_the_confirmation_box(): void
    {
        $this->assertFileExists(public_path('js/vendor/sweetalert2.all.min.js'));
        $this->assertFileExists(public_path('js/confirm.js'));

        $this->get('/users')->assertSeeInOrder([
            '<script src="'.versioned_asset('js/vendor/sweetalert2.all.min.js').'" defer></script>',
            '<script src="'.versioned_asset('js/confirm.js').'" defer></script>',
            '</body>',
        ], false);
    }

    public function test_user_forms_ask_before_saving_updating_and_deleting(): void
    {
        $this->get('/users/create')->assertSee($this->asks('Are you sure you want to save this user?', 'save'), false);
        $edit = "/users/edit/{$this->me->Id}";
        $this->get($edit)->assertSee($this->asks('Are you sure you want to use this photo for this user?', 'update'), false);
        $this->get("{$edit}?edit=info")->assertSee($this->asks('Are you sure you want to update this user?', 'update'), false);
        $this->get("{$edit}?edit=password")->assertSee($this->asks("Are you sure you want to change this user's password?", 'update'), false);
        $this->get("{$edit}?edit=roles")->assertSee($this->asks("Are you sure you want to change this user's roles?", 'update'), false);

        // Your own, on My profile.
        $this->get('/profile')->assertSee($this->asks('Are you sure you want to use this photo for your profile?', 'update'), false);
        $this->get('/profile?edit=info')->assertSee($this->asks('Are you sure you want to update your profile?', 'update'), false);
        $this->get('/profile?edit=password')->assertSee($this->asks('Are you sure you want to change your password?', 'update'), false);

        $other = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);
        $this->get("/users/delete/{$other->Id}")->assertSee($this->asks('Are you sure you want to delete this user?', 'delete'), false);
    }

    public function test_role_forms_ask_before_saving_updating_and_deleting(): void
    {
        $role = Role::create(['Name' => 'Admin']);

        $this->get('/roles/create')->assertSee($this->asks('Are you sure you want to save this role?', 'save'), false);
        $this->get("/roles/edit/{$role->Id}")->assertSee($this->asks('Are you sure you want to update this role?', 'update'), false);
        $this->get("/roles/delete/{$role->Id}")->assertSee($this->asks('Are you sure you want to delete this role?', 'delete'), false);
    }

    public function test_menu_item_forms_ask_before_saving_updating_and_deleting(): void
    {
        $item = MenuItem::where('Label', 'Roles')->firstOrFail();

        $this->get('/menu-items/create')->assertSee($this->asks('Are you sure you want to save this menu item?', 'save'), false);
        $this->get("/menu-items/edit/{$item->Id}")->assertSee($this->asks('Are you sure you want to update this menu item?', 'update'), false);
        $this->get("/menu-items/delete/{$item->Id}")->assertSee($this->asks('Are you sure you want to delete this menu item?', 'delete'), false);
    }

    public function test_route_forms_ask_before_saving_updating_and_deleting(): void
    {
        $reports = MenuItem::create(['Label' => 'Reports', 'SortOrder' => 9]);
        $monthly = MenuItem::create(['Label' => 'Monthly', 'SortOrder' => 1, 'ParentId' => $reports->Id]);
        $route = AppRoute::create([
            'MenuItemId' => $monthly->Id, 'Path' => 'all-roles',
            'Controller' => 'Setup\RoleController', 'Action' => 'index', 'HttpMethods' => 'GET',
        ]);

        $this->get('/routes')->assertSee(
            'data-confirm="Are you sure you want to save this route?"'."\n".'        data-confirm-kind="save"',
            false,
        );

        // Delete on the edit form asks, then sends the hidden delete form; without JavaScript it opens the delete page.
        $this->get("/routes/{$route->Id}/edit")
            ->assertSee('data-confirm="Are you sure you want to update this route?"'."\n".'        data-confirm-kind="update"', false)
            ->assertSee('href="'.route('routes.delete', $route).'" data-confirm-form="delete-route" '.$this->asks('Are you sure you want to delete this route?', 'delete'), false)
            ->assertSee('id="delete-route" hidden>', false);

        $this->get("/routes/{$route->Id}/delete")->assertSee($this->asks('Are you sure you want to delete this route?', 'delete'), false);
    }

    public function test_searching_and_logging_out_do_not_ask(): void
    {
        $this->get('/users')
            ->assertSee('<form method="get" action="'.route('users').'" class="search" role="search">', false)
            ->assertSee('<form method="post" action="'.route('logout').'">', false)
            ->assertSee('<form method="post" action="'.route('logout').'" class="side-row">', false);
    }
}
