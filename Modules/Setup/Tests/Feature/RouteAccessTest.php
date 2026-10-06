<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Someone with ADMIN, who can use the Routes page.
        $this->admin = User::create(['Name' => 'Admin', 'Email' => 'admin@example.com']);
        $this->admin->roles()->attach(Role::firstOrCreate(['Name' => Role::Admin])->Id);

        // Not ADMIN, so only what's open to everyone who is logged in.
        $this->me = $this->signIn(admin: false);
    }

    /**
     * Do something as the admin, like using the Routes page, then carry on as me.
     */
    private function asAdmin(Closure $steps): mixed
    {
        $this->actingAs($this->admin);

        try {
            return $steps();
        } finally {
            $this->actingAs($this->me);
        }
    }

    /**
     * Submit a saved route's form again, as the Routes page does, keeping what the test doesn't change.
     *
     * @param  array<string, mixed>  $fields
     */
    private function updateRoute(AppRoute $route, array $fields): void
    {
        $menu = $route->MenuItemId ?? MenuItem::create(['Label' => 'Somewhere', 'SortOrder' => 9])->Id;

        $this->asAdmin(fn () => $this->put("/routes/{$route->Id}", $fields + [
            'menu_item_id' => $menu, 'path' => $route->Path, 'controller' => $route->Controller,
            'function' => $route->Action, 'method' => $route->HttpMethods, 'parameter' => (string) $route->Parameters,
        ])->assertSessionHasNoErrors());

        AppRoute::registerBehindLogin();
    }

    public function test_the_apps_pages_and_their_actions_are_routes_on_the_routes_page_under_their_menu_links(): void
    {
        $routes = AppRoute::with('roles', 'menuItem')->orderBy('Path')->get();

        // Every page, and what it does, the AMV way: the action, then the record.
        $this->assertSame([
            'GET /crime-data' => 'Map\CrimeDataController@index',
            'POST /crime-data/apply/{upload}' => 'Map\CrimeDataController@apply',
            'DELETE /crime-data/cancel/{upload}' => 'Map\CrimeDataController@cancel',
            'POST /crime-data/compare/{upload}' => 'Map\CrimeDataController@compare',
            'GET /crime-data/delete/{crimeStat}' => 'Map\CrimeDataController@delete',
            'DELETE /crime-data/destroy/{crimeStat}' => 'Map\CrimeDataController@destroy',
            'GET /crime-data/download' => 'Map\CrimeDataController@download',
            'GET /crime-data/review/{upload}' => 'Map\CrimeDataController@review',
            'POST /crime-data/store' => 'Map\CrimeDataController@store',
            'PUT /crime-data/update' => 'Map\CrimeDataController@update',
            'POST /crime-data/upload' => 'Map\CrimeDataController@upload',
            'GET /dashboard' => 'Dashboard\DashboardController@index',
            'GET /map' => 'Map\MapController@index',
            'GET /map/crime' => 'Map\MapController@crime',
            'GET /menu-items' => 'Setup\MenuItemController@index',
            'GET /menu-items/create' => 'Setup\MenuItemController@create',
            'GET /menu-items/delete/{menu_item}' => 'Setup\MenuItemController@delete',
            'DELETE /menu-items/destroy/{menu_item}' => 'Setup\MenuItemController@destroy',
            'GET /menu-items/edit/{menu_item}' => 'Setup\MenuItemController@edit',
            'POST /menu-items/store' => 'Setup\MenuItemController@store',
            'PUT /menu-items/update/{menu_item}' => 'Setup\MenuItemController@update',
            'GET /police-stations' => 'Map\PoliceStationController@index',
            'POST /police-stations/apply/{upload}' => 'Map\PoliceStationController@apply',
            'DELETE /police-stations/cancel/{upload}' => 'Map\PoliceStationController@cancel',
            'POST /police-stations/compare/{upload}' => 'Map\PoliceStationController@compare',
            'GET /police-stations/create' => 'Map\PoliceStationController@create',
            'GET /police-stations/delete/{station}' => 'Map\PoliceStationController@delete',
            'DELETE /police-stations/destroy/{station}' => 'Map\PoliceStationController@destroy',
            'GET /police-stations/download' => 'Map\PoliceStationController@download',
            'GET /police-stations/edit/{station}' => 'Map\PoliceStationController@edit',
            'GET /police-stations/review/{upload}' => 'Map\PoliceStationController@review',
            'POST /police-stations/store' => 'Map\PoliceStationController@store',
            'PUT /police-stations/update/{station}' => 'Map\PoliceStationController@update',
            'POST /police-stations/upload' => 'Map\PoliceStationController@upload',
            'GET /roles' => 'Setup\RoleController@index',
            'GET /roles/create' => 'Setup\RoleController@create',
            'GET /roles/delete/{role}' => 'Setup\RoleController@delete',
            'DELETE /roles/destroy/{role}' => 'Setup\RoleController@destroy',
            'GET /roles/edit/{role}' => 'Setup\RoleController@edit',
            'POST /roles/store' => 'Setup\RoleController@store',
            'PUT /roles/update/{role}' => 'Setup\RoleController@update',
            'GET /users' => 'Setup\UserController@index',
            'GET /users/create' => 'Setup\UserController@create',
            'GET /users/delete/{user}' => 'Setup\UserController@delete',
            'DELETE /users/destroy/{user}' => 'Setup\UserController@destroy',
            'DELETE /users/destroy-photo/{user}' => 'Setup\UserPhotoController@destroy',
            'GET /users/edit/{user}' => 'Setup\UserController@edit',
            'GET /users/photo/{user}' => 'Setup\UserPhotoController@show',
            'POST /users/store' => 'Setup\UserController@store',
            'POST /users/store-photo/{user}' => 'Setup\UserPhotoController@store',
            'PUT /users/update/{user}' => 'Setup\UserController@update',
        ], $routes->mapWithKeys(fn (AppRoute $route) => [$route->label() => $route->handler()])->all());

        // The lists, the dashboard and the map are open to everyone logged in; adding, changing and deleting start
        // ADMIN-only, as the whole Crime data page does.
        $this->assertSame(['dashboard', 'map', 'map/crime', 'menu-items', 'roles', 'users'], $routes->filter->OpenToEveryone->pluck('Path')->all());
        $this->assertTrue($routes->reject->OpenToEveryone->every(fn (AppRoute $route) => $route->roles->pluck('Name')->all() === ['ADMIN']));

        // Each page under the menu link that opens it, and its actions under the same one; the Users link moved from /
        // to /users with its page.
        $menuOf = fn (string $path) => $routes->firstWhere('Path', $path)->menuItem?->Label;
        $this->assertSame(['Users', 'Add user', 'Roles', 'Roles', 'Manage menu'], array_map($menuOf, ['users', 'users/create', 'roles', 'roles/create', 'menu-items']));
        $this->assertSame(['Dashboard', 'Crime data', 'Crime data', 'Map', 'Map'], array_map($menuOf, ['dashboard', 'crime-data', 'crime-data/download', 'map', 'map/crime']));
        foreach ($routes as $route) {
            if (preg_match('~^(.+)/(store|edit|update|delete|destroy|upload|apply|cancel|photo|store-photo|destroy-photo)$~', $route->Path, $action)) {
                $this->assertSame($menuOf($action[1]), $route->menuItem?->Label, $route->Path);
            }
        }
        $this->assertSame('/users', MenuItem::firstWhere('Label', 'Users')->Url);

        // They answer at their addresses, named after them, and the site's address leads to the dashboard.
        foreach (['users' => 'Users', 'roles' => 'Roles', 'menu-items' => 'Navigation menu', 'dashboard' => 'Dashboard'] as $path => $heading) {
            $this->assertSame(url($path), route($path));
            $this->get("/{$path}")->assertOk()->assertSee("<h1>{$heading}</h1>", false);
        }
        $this->assertSame(url("/users/edit/{$this->me->Id}"), route('users/edit', ['user' => $this->me]));
        $this->get('/')->assertRedirect('/dashboard');

        // The Routes page shows them, and itself stays in the code.
        $this->asAdmin(fn () => $this->get('/routes')
            ->assertSee('<code class="route-address">/users/create</code>', false)
            ->assertSee('<code class="route-address">/users/edit/{user}</code>', false));
        $this->assertNull(AppRoute::firstWhere('Path', 'routes'));
    }

    public function test_only_admin_can_use_the_routes_page_even_typing_its_address(): void
    {
        $route = AppRoute::firstWhere('Path', 'users/edit');
        $fields = [
            'menu_item_id' => $route->MenuItemId, 'path' => 'users/edit', 'controller' => 'UserController', 'function' => 'edit',
            'method' => 'GET', 'parameter' => 'user', 'open_to' => 'everyone',
        ];

        // Otherwise anyone let in could open every other page to themselves.
        $this->get('/routes')->assertForbidden()->assertSee('Only users with the ADMIN role can open this page.');
        $this->get("/routes/{$route->Id}/edit")->assertForbidden();
        $this->get("/routes/{$route->Id}/delete")->assertForbidden();
        $this->post('/routes', ['path' => 'sneaky'] + $fields)->assertForbidden();
        $this->put("/routes/{$route->Id}", $fields)->assertForbidden();
        $this->delete("/routes/{$route->Id}")->assertForbidden();
        // Whether or not the route exists.
        $this->get('/routes/999/edit')->assertForbidden();

        $this->assertFalse($route->fresh()->OpenToEveryone);
        $this->assertNull(AppRoute::firstWhere('Path', 'sneaky'));

        // The ADMIN role opens it, whatever the Routes page says about anything else.
        $this->me->roles()->attach(Role::firstWhere('Name', Role::Admin)->Id);
        $this->get('/routes')->assertOk()->assertSee('This page is only for the ADMIN role.');
        $this->get("/routes/{$route->Id}/edit")->assertOk();
    }

    public function test_without_the_dashboard_or_users_list_logging_in_leads_admin_to_routes_and_others_to_their_profile(): void
    {
        AppRoute::whereIn('Path', ['dashboard', 'users'])->delete();
        AppRoute::registerBehindLogin();

        $this->get('/')->assertRedirect('/profile');
        $this->asAdmin(fn () => $this->get('/')->assertRedirect('/routes'));
    }

    public function test_without_the_role_no_add_edit_or_delete_route_opens_even_typing_its_address(): void
    {
        $other = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);
        $role = Role::create(['Name' => 'Editor']);
        $item = MenuItem::create(['Label' => 'Reports', 'Url' => '/reports', 'SortOrder' => 9]);

        foreach (['users' => [$other->Id, ['name' => 'Sneaky', 'username' => 'sneaky', 'email' => 'sneaky@example.com']], 'roles' => [$role->Id, ['name' => 'Sneaky']], 'menu-items' => [$item->Id, ['label' => 'Sneaky', 'url' => '/sneaky', 'sort_order' => 1]]] as $page => [$id, $fields]) {
            $this->get("/{$page}")->assertOk();
            $this->get("/{$page}/create")->assertForbidden()->assertSee('Only users with the ADMIN role can open this page.');
            $this->post("/{$page}/store", $fields)->assertForbidden();
            $this->get("/{$page}/edit/{$id}")->assertForbidden();
            $this->put("/{$page}/update/{$id}", $fields)->assertForbidden();
            $this->get("/{$page}/delete/{$id}")->assertForbidden();
            $this->delete("/{$page}/destroy/{$id}")->assertForbidden();

            // Whether or not the record exists: "Not allowed" rather than "Not found" gives nothing away.
            $this->get("/{$page}/edit/999")->assertForbidden();
            $this->delete("/{$page}/destroy/999")->assertForbidden();
        }

        $this->assertSame('Siti Aminah', $other->fresh()->Name);
        $this->assertSame('Editor', $role->fresh()->Name);
        $this->assertSame('Reports', $item->fresh()->Label);
        $this->assertDatabaseMissing('Users', ['Email' => 'sneaky@example.com']);
        $this->assertDatabaseMissing('Roles', ['Name' => 'Sneaky']);
        $this->assertDatabaseMissing('MenuItems', ['Label' => 'Sneaky']);
    }

    public function test_each_action_has_its_own_roles(): void
    {
        $editor = Role::create(['Name' => 'Editor']);
        $this->me->roles()->attach($editor->Id);
        $other = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);

        // Editors may edit users, which is the edit page and the saving it leads to, but not add or delete them.
        foreach (['users/edit', 'users/update'] as $path) {
            $this->updateRoute(AppRoute::firstWhere('Path', $path), ['open_to' => 'roles', 'roles' => [$editor->Id]]);
        }

        $this->get("/users/edit/{$other->Id}")->assertOk()->assertSee('siti@example.com');
        $this->put("/users/update/{$other->Id}", ['section' => 'info', 'name' => 'Siti', 'username' => 'siti', 'email' => 'siti@example.com'])->assertRedirect("/users/edit/{$other->Id}");
        $this->assertSame('Siti', $other->fresh()->Name);

        $this->get('/users/create')->assertForbidden();
        $this->get("/users/delete/{$other->Id}")->assertForbidden();
        $this->delete("/users/destroy/{$other->Id}")->assertForbidden();
        $this->assertNotNull($other->fresh());

        // The Routes page shows who can use each.
        $this->asAdmin(fn () => $this->get('/routes')->assertSeeInOrder([
            '<code class="route-address">/users/edit/{user}</code>', '<span class="material-icon" aria-hidden="true">lock</span>Editor',
        ], false));
    }

    public function test_a_route_can_be_limited_to_roles(): void
    {
        $editor = Role::create(['Name' => 'Editor']);
        $menu = MenuItem::create(['Label' => 'Reports', 'Url' => '/reports', 'SortOrder' => 9]);

        $this->asAdmin(fn () => $this->post('/routes', [
            'menu_item_id' => $menu->Id, 'path' => 'reports', 'controller' => 'RoleController', 'function' => 'index',
            'method' => 'GET', 'parameter' => '', 'open_to' => 'roles', 'roles' => [$editor->Id],
        ])->assertSessionHasNoErrors());
        AppRoute::registerBehindLogin();

        $route = AppRoute::firstWhere('Path', 'reports');
        $this->assertFalse($route->OpenToEveryone);
        $this->assertSame([$editor->Id], $route->roles->pluck('Id')->all());

        // The list marks it, and the form shows who can open it.
        $this->asAdmin(function () use ($route, $editor) {
            $this->get('/routes')->assertSee('<span class="material-icon" aria-hidden="true">lock</span>Editor', false);
            $this->get("/routes/{$route->Id}/edit")
                ->assertSee('<legend>Who can open it</legend>', false)
                ->assertSee('name="open_to" value="roles" checked', false)
                ->assertSee('name="roles[]" value="'.$editor->Id.'" checked', false);
        });

        // Checked when the page opens, not just hidden from the menu.
        $this->get('/reports')->assertForbidden()->assertSee('Only users with the Editor role can open this page.');
        $this->me->roles()->attach($editor->Id);
        $this->get('/reports')->assertOk()->assertSee('<h1>Roles</h1>', false);

        // Open to everyone again, it forgets the roles.
        $this->me->roles()->detach();
        $this->updateRoute($route, ['open_to' => 'everyone', 'roles' => [$editor->Id]]);
        $this->assertTrue($route->fresh()->OpenToEveryone);
        $this->assertCount(0, $route->fresh()->roles);
        $this->get('/reports')->assertOk();
    }

    public function test_limiting_a_route_to_roles_needs_a_role(): void
    {
        $menu = MenuItem::create(['Label' => 'Reports', 'Url' => '/reports', 'SortOrder' => 9]);

        $this->asAdmin(fn () => $this->post('/routes', [
            'menu_item_id' => $menu->Id, 'path' => 'reports', 'controller' => 'RoleController', 'function' => 'index',
            'method' => 'GET', 'parameter' => '', 'open_to' => 'roles',
        ])->assertSessionHasErrors(['roles' => 'Tick at least one role, or choose "Everyone who is logged in".']));

        $this->assertNull(AppRoute::firstWhere('Path', 'reports'));
    }

    public function test_a_route_whose_roles_are_all_deleted_opens_for_no_one(): void
    {
        $editor = Role::create(['Name' => 'Editor']);
        $route = AppRoute::create([
            'Path' => 'reports', 'Controller' => 'Setup\RoleController', 'Action' => 'index', 'HttpMethods' => 'GET', 'OpenToEveryone' => false,
        ]);
        $route->roles()->attach($editor->Id);
        $this->me->roles()->attach($editor->Id);
        $editor->delete();
        AppRoute::registerBehindLogin();

        $this->get('/reports')->assertForbidden()->assertSee('No one can open this page: the roles it was limited to have been deleted.');
        $this->asAdmin(fn () => $this->get('/routes')->assertSee('<span class="material-icon" aria-hidden="true">lock</span>No one', false));
    }

    public function test_crime_data_and_its_actions_are_for_admin(): void
    {
        $routes = AppRoute::with('roles')->where('Path', 'like', 'crime-data%')->get();
        $this->assertCount(11, $routes);
        $this->assertTrue($routes->every(fn (AppRoute $route) => $route->roles->pluck('Name')->all() === ['ADMIN']));

        // Not ADMIN: neither the page, its download, nor its forms.
        $this->get('/crime-data')->assertForbidden()->assertSee('Only users with the ADMIN role can open this page.');
        $this->get('/crime-data/download')->assertForbidden();
        $this->put('/crime-data/update', ['crimes' => ['1' => '5'], 'original' => ['1' => '4']])->assertForbidden();

        $this->me->roles()->attach(Role::firstWhere('Name', 'ADMIN')->Id);
        $this->get('/crime-data')->assertOk();
        $this->put('/crime-data/update', ['crimes' => ['1' => '5'], 'original' => ['1' => '5']])->assertRedirect();

        // Opening the page to everyone opens only the page: saving is a route of its own.
        $this->me->roles()->detach();
        $this->updateRoute($routes->firstWhere('Path', 'crime-data'), ['open_to' => 'everyone']);
        $this->get('/crime-data')->assertOk();
        $this->put('/crime-data/update', ['crimes' => ['1' => '5'], 'original' => ['1' => '5']])->assertForbidden();

        // Deleted from the Routes page, an action stops answering from the next request.
        $routes->firstWhere('Path', 'crime-data/update')->delete();
        AppRoute::registerBehindLogin();
        $this->put('/crime-data/update', ['crimes' => ['1' => '5'], 'original' => ['1' => '5']])->assertNotFound();
        $this->get('/crime-data')->assertOk();
    }

    public function test_my_profile_is_for_everyone_logged_in_and_never_changes_roles(): void
    {
        // Even with the users list limited, and editing users ADMIN-only.
        $admin = Role::firstWhere('Name', 'ADMIN');
        $this->updateRoute(AppRoute::firstWhere('Path', 'users'), ['open_to' => 'roles', 'roles' => [$admin->Id]]);
        $this->get('/users')->assertForbidden();
        $this->get("/users/edit/{$this->me->Id}")->assertForbidden();

        $this->get('/profile')
            ->assertOk()
            ->assertSee('<h1>Edit profile</h1>', false)
            ->assertSee('<dt>Email</dt><dd>me@example.com</dd>', false)
            ->assertSee('Only an administrator can change your roles.');
        $this->get('/profile?edit=info')
            ->assertSee('action="'.route('profile.update').'"', false)
            ->assertSee('value="me@example.com"', false);

        // Roles can't be edited here, even asking for it or sending them anyway: no one gives themselves ADMIN.
        foreach (['/profile', '/profile?edit=roles'] as $page) {
            $this->get($page)->assertDontSee('name="roles[]"', false)->assertDontSee('?edit=roles', false);
        }
        foreach ([[], ['section' => 'roles'], ['section' => 'info']] as $section) {
            $this->put('/profile', $section + ['name' => 'Me Again', 'username' => 'userme', 'email' => 'me@example.com', 'roles' => [$admin->Id]])
                ->assertRedirect('/profile')
                ->assertSessionHas('message', 'Saved your changes.');
        }
        $this->assertSame('Me Again', $this->me->fresh()->Name);
        $this->assertCount(0, $this->me->fresh()->roles);

        // It's always your own details, checked like any user's.
        User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);
        $this->put('/profile', ['name' => 'Me', 'username' => 'userme', 'email' => 'siti@example.com'])->assertSessionHasErrors('email');
        $this->assertSame('me@example.com', $this->me->fresh()->Email);

        auth()->logout();
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_links_to_a_page_that_left_the_routes_page_fall_back_to_its_address(): void
    {
        $this->assertSame(url('/roles/create'), page_url('roles/create'));
        $this->assertSame(url('/crime-data?state=Johor&year=2023'), page_url('crime-data', ['state' => 'Johor', 'year' => 2023]));

        // Not on the Routes page: its plain address, which shows "Not found", rather than an error.
        $this->assertSame(url('/no-such-page'), page_url('no-such-page'));
        $this->assertSame(url('/no-such-page?page=2'), page_url('no-such-page', ['page' => 2]));
    }
}
