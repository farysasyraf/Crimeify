<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AppRoutePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    // A level 1 link with a level 2 link under it, the kind of menu link routes go under.
    private MenuItem $reports;

    private MenuItem $monthly;

    /**
     * The Ids of the app's own pages that the migrations save on the Routes page, like users and roles.
     *
     * @var list<int>
     */
    private array $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->signIn();
        $this->reports = MenuItem::create(['Label' => 'Reports', 'SortOrder' => 9]);
        $this->monthly = MenuItem::create(['Label' => 'Monthly', 'SortOrder' => 1, 'ParentId' => $this->reports->Id]);
        $this->pages = AppRoute::pluck('Id')->all();
    }

    /**
     * The routes the test saves, leaving out the app's own pages.
     *
     * @return Builder<AppRoute>
     */
    private function added(): Builder
    {
        return AppRoute::query()->whereKeyNot($this->pages);
    }

    /**
     * Submit the route form. Fields the test doesn't give default to a GET route to the Roles page under Monthly.
     *
     * @param  array<string, mixed>  $fields
     */
    private function saveRoute(array $fields, ?AppRoute $route = null): TestResponse
    {
        $fields += [
            'menu_item_id' => $this->monthly->Id,
            'controller' => 'RoleController',
            'function' => 'index',
            'method' => 'GET',
            'parameter' => '',
        ];

        return $route === null
            ? $this->post('/routes', $fields)
            : $this->put("/routes/{$route->Id}", $fields);
    }

    /**
     * Add the saved routes again, as the app does once it has booted. In tests it boots before the database
     * has its tables, so saved routes only reach the router this way, as on the next real request.
     */
    private function reloadRoutes(): void
    {
        AppRoute::registerBehindLogin();
    }

    public function test_page_shows_the_form_with_menu_links_grouped_as_in_amv(): void
    {
        $weekly = MenuItem::create(['Label' => 'Weekly', 'SortOrder' => 2, 'ParentId' => $this->reports->Id]);
        $week1 = MenuItem::create(['Label' => 'Week 1', 'SortOrder' => 1, 'ParentId' => $weekly->Id]);

        $page = $this->get('/routes')
            ->assertOk()
            ->assertSee('Add route')
            ->assertSeeInOrder([
                '<optgroup label="Reports">',
                '<option value="'.$this->monthly->Id.'" >Monthly</option>',
                '<option value="'.$week1->Id.'" >Weekly / Week 1</option>',
                '</optgroup>',
            ], false)
            ->assertSee('<option value="Setup\RoleController"></option>', false)
            ->assertSee('<span class="route-group-label">Monthly</span>', false);

        // Level 1 links with nothing under them, like Manage menu, can have routes too, in a group of their own first.
        $page->assertSeeInOrder([
            '<optgroup label="Top of the menu">', '>Manage menu</option>', '</optgroup>', '<optgroup label="Reports">',
        ], false);

        // Links with links under them only group the choices.
        $page->assertDontSee('<option value="'.$this->reports->Id.'"', false)
            ->assertDontSee('<option value="'.$weekly->Id.'"', false);
    }

    public function test_a_saved_route_answers_at_its_address_and_is_named_after_it(): void
    {
        $this->saveRoute(['path' => '/all-roles'])->assertSessionHas('message', 'Added GET /all-roles.');

        $route = $this->added()->firstOrFail();
        $this->assertSame(
            [$this->monthly->Id, 'all-roles', null, 'Setup\RoleController', 'index', 'GET'],
            [$route->MenuItemId, $route->Path, $route->Parameters, $route->Controller, $route->Action, $route->HttpMethods],
        );

        $this->reloadRoutes();
        Role::create(['Name' => 'Auditor']);

        $this->assertSame(url('/all-roles'), route('all-roles'));
        $this->get('/all-roles')->assertOk()->assertSee('<h1>Roles</h1>', false)->assertSee('Auditor');
        $this->post('/all-roles')->assertMethodNotAllowed();

        $this->post('/logout');
        $this->get('/all-roles')->assertRedirect('/login');
    }

    public function test_saving_opens_the_route_for_editing(): void
    {
        $response = $this->saveRoute(['path' => 'all-roles']);

        $route = $this->added()->firstOrFail();
        $response->assertRedirect("/routes/{$route->Id}/edit");

        $this->get("/routes/{$route->Id}/edit")
            ->assertOk()
            ->assertSee('Added GET /all-roles.')
            ->assertSee('Edit route')
            ->assertSee('value="all-roles"', false)
            ->assertSee('<option value="'.$this->monthly->Id.'" selected>Monthly</option>', false)
            ->assertSee('name="method" value="GET" checked', false)
            ->assertSee('>Update</button>', false)
            ->assertSee('data-confirm="Are you sure you want to update this route?"', false)
            ->assertSee('data-confirm="Are you sure you want to delete this route?"', false)
            ->assertSee('<form method="post" action="'.route('routes.destroy', $route).'" id="delete-route" hidden>', false);
    }

    public function test_controllers_are_found_in_the_app_and_in_its_modules(): void
    {
        // A controller in app/Http/Controllers keeps its own name; one in a module gets the module's name in front.
        $this->saveRoute(['path' => 'sign-in-again', 'controller' => 'LoginController', 'function' => 'show'])->assertSessionHasNoErrors();
        $this->saveRoute(['path' => 'all-roles', 'controller' => 'Setup/RoleController'])->assertSessionHasNoErrors();

        $this->assertSame(['LoginController', 'Setup\RoleController'], $this->added()->orderBy('Id')->pluck('Controller')->all());

        $this->get('/routes')
            ->assertSee('<option value="LoginController"></option>', false)
            ->assertSee('<option value="Setup\UserController"></option>', false);

        $this->reloadRoutes();

        $this->get('/all-roles')->assertOk()->assertSee('<h1>Roles</h1>', false);
    }

    public function test_a_controller_name_in_more_than_one_folder_needs_its_module(): void
    {
        // Two controllers with the same name, one in the app and one in the Setup module, made only for this test.
        eval('namespace App\Http\Controllers; class TwinTestController extends Controller { public function index() {} }');
        eval('namespace Modules\Setup\Http\Controllers; class TwinTestController extends \App\Http\Controllers\Controller { public function index() {} }');

        $this->saveRoute(['path' => 'twin', 'controller' => 'TwinTestController'])->assertSessionHasErrors([
            'controller' => 'More than one controller is called TwinTestController: TwinTestController and Setup\TwinTestController. Type the one you mean, with its module.',
        ]);

        $this->saveRoute(['path' => 'twin', 'controller' => 'Setup\TwinTestController'])->assertSessionHasNoErrors();
        $this->assertSame('Setup\TwinTestController', $this->added()->firstOrFail()->Controller);
    }

    public function test_parameters_are_names_that_reach_the_function_and_bind_models(): void
    {
        $other = User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com']);

        $this->saveRoute(['path' => 'people', 'controller' => 'UserController', 'function' => 'edit', 'parameter' => 'user'])
            ->assertSessionHasNoErrors();

        $this->assertSame('user', $this->added()->firstOrFail()->Parameters);

        $this->reloadRoutes();

        $this->assertSame(url("/people/{$other->Id}"), route('people', ['user' => $other->Id]));
        $this->get("/people/{$other->Id}")->assertOk()->assertSee('<dd>Siti Aminah</dd>', false);
        $this->get('/people/999')->assertNotFound();
    }

    public function test_form_input_is_tidied_before_saving(): void
    {
        $this->saveRoute([
            'path' => '/reports/monthly/',
            'controller' => '\\Modules\\Setup\\Http\\Controllers\\RoleController',
            'function' => ' index ',
            'method' => 'POST',
            'parameter' => ' {year}, month? ',
        ])->assertSessionHas('message', 'Added POST /reports/monthly/{year}/{month?}.');

        $route = $this->added()->firstOrFail();
        $this->assertSame(
            ['reports/monthly', 'year/month?', 'Setup\RoleController', 'index', 'POST'],
            [$route->Path, $route->Parameters, $route->Controller, $route->Action, $route->HttpMethods],
        );
    }

    public function test_put_and_delete_routes_answer_only_their_method(): void
    {
        $this->get('/routes')
            ->assertSeeInOrder(['value="GET"', 'value="POST"', 'value="PUT"', 'value="DELETE"', 'value="ANY"'], false)
            ->assertSee('PUT saves changes to a record and DELETE deletes one.');

        $this->saveRoute(['path' => 'reports/save', 'method' => 'PUT', 'parameter' => 'role', 'controller' => 'RoleController', 'function' => 'update'])
            ->assertSessionHas('message', 'Added PUT /reports/save/{role}.');
        $this->saveRoute(['path' => 'reports/remove', 'method' => 'DELETE', 'parameter' => 'role', 'controller' => 'RoleController', 'function' => 'destroy'])
            ->assertSessionHas('message', 'Added DELETE /reports/remove/{role}.');

        $this->reloadRoutes();

        $role = Role::create(['Name' => 'Auditor']);
        $this->get("/reports/save/{$role->Id}")->assertMethodNotAllowed();
        $this->put("/reports/save/{$role->Id}", ['name' => 'Checker'])->assertRedirect();
        $this->assertSame('Checker', $role->fresh()->Name);
        $this->delete("/reports/remove/{$role->Id}")->assertRedirect();
        $this->assertNull($role->fresh());

        $this->get('/routes')
            ->assertSee('<span class="method method-put">PUT</span>', false)
            ->assertSee('<span class="method method-delete">DELETE</span>', false);
    }

    public function test_any_answers_every_method(): void
    {
        $this->saveRoute(['path' => 'everything', 'method' => 'ANY'])->assertSessionHasNoErrors();

        $this->reloadRoutes();

        $this->get('/everything')->assertOk();
        $this->post('/everything')->assertOk();
        $this->delete('/everything')->assertOk();
    }

    public function test_form_validates_input(): void
    {
        $this->post('/routes', [])
            ->assertSessionHasErrors(['menu_item_id', 'path', 'controller', 'function', 'method']);

        $this->saveRoute([
            'menu_item_id' => 999,
            'path' => 'reports/{id}',
            'controller' => 'NoSuchController',
            'method' => 'PATCH',
            'parameter' => 'id/id',
        ])->assertSessionHasErrors([
            'menu_item_id' => 'Choose a menu from the list. Routes go under a menu link with nothing under it.',
            'path',
            'controller' => "There's no controller named NoSuchController in app/Http/Controllers or a module's Http/Controllers.",
            'method',
            'parameter',
        ]);

        // A link with links under it only groups the choices.
        $this->saveRoute(['path' => 'x', 'menu_item_id' => $this->reports->Id])->assertSessionHasErrors('menu_item_id');

        // Only the app's controllers, and only functions a route may call.
        $this->saveRoute(['path' => 'x', 'controller' => 'Controller'])->assertSessionHasErrors('controller');
        $this->saveRoute(['path' => 'x', 'controller' => '..\\..\\Models\\User'])->assertSessionHasErrors('controller');
        $this->saveRoute(['path' => 'x', 'controller' => 'Illuminate\\Routing\\Controller'])->assertSessionHasErrors('controller');
        $this->saveRoute(['path' => 'x', 'function' => 'validated'])
            ->assertSessionHasErrors(['function' => 'RoleController has no public function named validated.']);
        $this->saveRoute(['path' => 'x', 'function' => '__construct'])->assertSessionHasErrors('function');

        // Names only, each once, and a required one can't follow an optional one.
        $this->saveRoute(['path' => 'x', 'parameter' => 'year?/month'])->assertSessionHasErrors('parameter');
        $this->saveRoute(['path' => 'x', 'parameter' => '1st'])->assertSessionHasErrors('parameter');
        $this->saveRoute(['path' => 'x', 'parameter' => '{id'])->assertSessionHasErrors('parameter');

        $this->assertSame(0, $this->added()->count());
    }

    public function test_an_address_or_name_a_built_in_page_has_is_refused(): void
    {
        $this->saveRoute(['path' => 'routes'])
            ->assertSessionHasErrors(['path' => 'GET /routes is already a page in this app. Choose another route name or method.']);

        // Parameter names don't matter: routes/{id} is the same address as routes/{app_route}, which takes PUT, PATCH
        // and DELETE.
        $this->saveRoute(['path' => 'routes', 'parameter' => 'id', 'method' => 'ANY'])
            ->assertSessionHasErrors(['path' => 'PUT/PATCH /routes/{id} is already a page in this app. Choose another route name or method.']);
        $this->saveRoute(['path' => 'Routes', 'method' => 'POST'])->assertSessionHasErrors('path');
        $this->saveRoute(['path' => 'routes', 'method' => 'ANY'])->assertSessionHasErrors('path');
        $this->saveRoute(['path' => 'profile'])
            ->assertSessionHasErrors(['path' => 'GET /profile is already a page in this app. Choose another route name or method.']);

        // The name would clash even though the address is free.
        $this->saveRoute(['path' => 'routes.edit'])
            ->assertSessionHasErrors(['path' => 'routes.edit is already the name of a page in this app. Choose another route name.']);

        // The app's pages and their add, edit and delete routes saved here are routes like any other.
        $this->saveRoute(['path' => 'users/create'])
            ->assertSessionHasErrors(['path' => 'GET /users/create is already a route, handled by Setup\UserController@create. Choose another route name or method.']);
        $this->saveRoute(['path' => 'users/edit', 'parameter' => 'id', 'method' => 'ANY'])
            ->assertSessionHasErrors(['path' => 'GET /users/edit/{user} is already a route, handled by Setup\UserController@edit. Choose another route name or method.']);
        $this->saveRoute(['path' => 'users/destroy', 'parameter' => 'id', 'method' => 'DELETE'])
            ->assertSessionHasErrors(['path' => 'DELETE /users/destroy/{user} is already a route, handled by Setup\UserController@destroy. Choose another route name or method.']);

        $this->assertSame(0, $this->added()->count());

        // The same address with a method nothing answers yet is fine.
        $this->saveRoute(['path' => 'users/create', 'method' => 'POST'])->assertSessionHasNoErrors();
        $this->saveRoute(['path' => 'users', 'parameter' => 'id'])->assertSessionHasNoErrors();
    }

    public function test_an_address_another_saved_route_answers_is_refused_unless_the_method_differs(): void
    {
        $this->saveRoute(['path' => 'reports', 'parameter' => 'id'])->assertSessionHasNoErrors();

        $this->saveRoute(['path' => 'reports', 'parameter' => 'month', 'method' => 'ANY'])
            ->assertSessionHasErrors(['path' => 'GET /reports/{id} is already a route, handled by Setup\RoleController@index. Choose another route name or method.']);

        $this->saveRoute(['path' => 'reports', 'parameter' => 'month', 'method' => 'POST'])->assertSessionHasNoErrors();

        // Saving a route unchanged doesn't clash with itself.
        $route = $this->added()->where('HttpMethods', 'GET')->firstOrFail();
        $this->saveRoute(['path' => 'reports', 'parameter' => 'id'], $route)->assertSessionHasNoErrors();
    }

    public function test_routes_sharing_a_path_share_the_first_ones_name(): void
    {
        $this->saveRoute(['path' => 'reports']);
        $this->saveRoute(['path' => 'reports', 'method' => 'POST', 'controller' => 'UserController', 'function' => 'store']);

        $this->reloadRoutes();

        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('reports')->methods());
        $this->get('/reports')->assertOk()->assertSee('<h1>Roles</h1>', false);
        $this->post('/reports', [])->assertSessionHasErrors('name');
    }

    public function test_a_saved_route_never_replaces_a_built_in_page_or_takes_its_name(): void
    {
        // Written straight to the database, past the form's checks.
        foreach (['routes', 'routes.edit'] as $path) {
            AppRoute::create([
                'MenuItemId' => $this->monthly->Id, 'Path' => $path,
                'Controller' => 'Setup\RoleController', 'Action' => 'index', 'HttpMethods' => 'GET',
            ]);
        }

        $this->reloadRoutes();

        $this->get('/routes')->assertOk()->assertSee('<h1>Routes</h1>', false)->assertDontSee('<h1>Roles</h1>', false);
        $this->assertSame(url('/routes/5/edit'), route('routes.edit', 5));
    }

    public function test_fixed_addresses_are_matched_before_ones_with_parameters(): void
    {
        $this->saveRoute(['path' => 'reports', 'parameter' => 'user', 'controller' => 'UserController', 'function' => 'edit'])->assertSessionHasNoErrors();
        $this->saveRoute(['path' => 'reports/list']);

        $this->reloadRoutes();

        $this->get('/reports/list')->assertOk()->assertSee('<h1>Roles</h1>', false);
        $this->get('/reports/'.$this->me->Id)->assertOk()->assertSee('Edit user');
    }

    public function test_the_edited_routes_menu_groups_are_open_and_others_closed(): void
    {
        $this->saveRoute(['path' => 'reports/june']);
        $route = $this->added()->firstOrFail();

        $this->get("/routes/{$route->Id}/edit")
            ->assertSeeInOrder(['<details class="route-group" open>', 'Reports', '<details class="route-group" open>', 'Monthly', 'route-row is-highlighted', '/reports/june', 'Setup\RoleController@index'], false)
            ->assertSee('aria-current="true"', false);

        $this->get('/routes')
            ->assertSeeInOrder(['<details class="route-group" >', 'Reports', '<details class="route-group" >', 'Monthly', '/reports/june'], false)
            ->assertDontSee('is-highlighted')
            ->assertSee('data-confirm="Are you sure you want to save this route?"', false)
            ->assertSee('>Save</button>', false);
    }

    public function test_update_saves_changes_and_stays_on_the_route(): void
    {
        $this->saveRoute(['path' => 'old-address']);
        $route = $this->added()->firstOrFail();

        $this->saveRoute(['path' => 'new-address', 'controller' => 'UserController', 'function' => 'create', 'method' => 'ANY'], $route)
            ->assertRedirect("/routes/{$route->Id}/edit")
            ->assertSessionHas('message', 'Saved changes to ANY /new-address.');

        $route->refresh();
        $this->assertSame(['new-address', 'Setup\UserController', 'create', 'ANY'], [$route->Path, $route->Controller, $route->Action, $route->HttpMethods]);
    }

    public function test_delete_asks_for_confirmation_then_removes_the_route(): void
    {
        $this->saveRoute(['path' => 'all-roles']);
        $route = $this->added()->firstOrFail();

        // What the Delete button opens when JavaScript is off.
        $this->get("/routes/{$route->Id}/delete")
            ->assertOk()
            ->assertSee('Are you sure you want to delete this route?')
            ->assertSee('<code>/all-roles</code> stops answering GET requests', false);

        $this->delete("/routes/{$route->Id}")
            ->assertRedirect('/routes')
            ->assertSessionHas('message', 'Deleted GET /all-roles.');

        $this->assertDatabaseMissing('AppRoutes', ['Id' => $route->Id]);
    }

    public function test_search_matches_routes_and_menu_labels_and_marks_the_matches(): void
    {
        $weekly = MenuItem::create(['Label' => 'Weekly', 'SortOrder' => 2, 'ParentId' => $this->reports->Id]);
        $this->saveRoute(['path' => 'reports/june']);
        $this->saveRoute(['path' => 'people', 'controller' => 'UserController', 'function' => 'create', 'menu_item_id' => $weekly->Id]);

        $this->get('/routes?search=usercontroller')
            ->assertSee('<mark>UserController</mark>@create', false)
            ->assertDontSee('/reports/june')
            ->assertSee('<details class="route-group" open>', false);

        // A menu link whose label matches keeps all its routes.
        $this->get('/routes?search=month')
            ->assertSee('<mark>Month</mark>ly', false)
            ->assertSee('/reports/june')
            ->assertDontSee('/people');

        $this->get('/routes?search=nothing-like-this')->assertSee('Nothing matches “nothing-like-this”.', false);
    }

    public function test_search_marks_are_safe_around_html_in_labels(): void
    {
        MenuItem::create(['Label' => 'R&D <b>', 'SortOrder' => 3, 'ParentId' => $this->reports->Id]);

        $this->get('/routes?search=%26')->assertSee('R<mark>&amp;</mark>D &lt;b&gt;', false);
    }

    public function test_deleting_a_menu_link_keeps_its_routes_under_no_menu(): void
    {
        $this->saveRoute(['path' => 'reports/june']);
        $this->saveRoute(['path' => 'reports/july']);

        $this->get("/menu-items/delete/{$this->reports->Id}")
            ->assertSee('2 routes are grouped under this link or the links under it.');

        $this->delete("/menu-items/destroy/{$this->reports->Id}");

        $this->assertSame(2, $this->added()->whereNull('MenuItemId')->count());

        $this->get('/routes')->assertSeeInOrder(['No menu', '/reports/july', '/reports/june']);

        // It needs a menu again before it can be saved.
        $this->saveRoute(['path' => 'reports/july', 'menu_item_id' => ''], AppRoute::where('Path', 'reports/july')->firstOrFail())
            ->assertSessionHasErrors(['menu_item_id' => 'Choose the menu this route belongs to.']);
    }

    public function test_routes_whose_code_is_gone_are_flagged(): void
    {
        AppRoute::create([
            'MenuItemId' => $this->monthly->Id, 'Path' => 'gone',
            'Controller' => 'RemovedController', 'Action' => 'index', 'HttpMethods' => 'GET',
        ]);
        $this->saveRoute(['path' => 'fine']);

        $page = $this->get('/routes')->assertSeeInOrder(['/fine', '/gone', 'Code not found'], false);
        $this->assertSame(1, substr_count($page->getContent(), 'Code not found'));
    }

    public function test_only_routes_that_open_without_parameters_get_an_open_button(): void
    {
        $this->saveRoute(['path' => 'plain']);
        $this->saveRoute(['path' => 'optional', 'parameter' => 'page?']);
        $this->saveRoute(['path' => 'needs-id', 'parameter' => 'id']);
        $this->saveRoute(['path' => 'post-only', 'method' => 'POST']);
        $this->saveRoute(['path' => 'any', 'method' => 'ANY']);

        $this->get('/routes')
            ->assertSee('href="'.url('plain').'" target="_blank"', false)
            ->assertSee('href="'.url('optional').'" target="_blank"', false)
            ->assertSee('href="'.url('any').'" target="_blank"', false)
            ->assertDontSee('href="'.url('needs-id').'" target="_blank"', false)
            ->assertDontSee('href="'.url('post-only').'" target="_blank"', false);
    }

    public function test_missing_route_returns_not_found(): void
    {
        $this->get('/routes/999/edit')->assertNotFound();
        $this->get('/routes/999/delete')->assertNotFound();
        $this->put('/routes/999')->assertNotFound();
        $this->delete('/routes/999')->assertNotFound();
    }
}
