<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Role;
use Farysasyraf\SavedRoutes\Tests\Fixtures\User;
use Farysasyraf\SavedRoutes\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;

class AdminPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define(SavedRoutes::GATE, fn (User $user) => $user->roles()->where('name', 'admin')->exists());
        $this->signIn(['admin']);
    }

    /**
     * Submit the form. Fields the test doesn't give default to a GET route to PageController@about.
     *
     * @param  array<string, mixed>  $fields
     */
    private function submit(array $fields, ?SavedRoute $route = null): TestResponse
    {
        $fields += ['controller' => 'PageController', 'function' => 'about', 'method' => 'GET', 'parameter' => ''];

        return $route === null
            ? $this->post('/saved-routes', $fields)
            : $this->put("/saved-routes/{$route->id}", $fields);
    }

    public function test_only_users_the_gate_lets_in_can_use_it(): void
    {
        $route = SavedRoute::create(['path' => 'about', 'controller' => 'PageController', 'action' => 'about', 'method' => 'GET']);
        $this->signIn();

        $this->get('/saved-routes')->assertForbidden();
        $this->get("/saved-routes/{$route->id}/edit")->assertForbidden();
        $this->get('/saved-routes/999/edit')->assertForbidden();
        $this->submit(['path' => 'sneaky'])->assertForbidden();
        $this->submit(['path' => 'sneaky'], $route)->assertForbidden();
        $this->delete("/saved-routes/{$route->id}")->assertForbidden();
        $this->assertSame('about', $route->fresh()->path);

        auth()->logout();
        $this->get('/saved-routes')->assertRedirect('/login');
    }

    public function test_the_page_shows_the_form_and_the_controllers_to_choose_from(): void
    {
        $this->get('/saved-routes')
            ->assertOk()
            ->assertSee('<h2>Add route</h2>', false)
            ->assertSee('<option value="Admin\AuditController"></option>', false)
            ->assertSee('No routes yet.')
            ->assertSee('<legend>Who can open it</legend>', false)
            ->assertSee('<span>admin</span>', false);
    }

    public function test_a_saved_route_answers_at_its_address_from_the_next_request(): void
    {
        $this->submit(['path' => '/about-us/'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('saved-routes.message', 'Added GET /about-us.');

        $route = SavedRoute::sole();
        $this->assertSame(['about-us', null, 'PageController', 'about', 'GET', null], [$route->path, $route->parameters, $route->controller, $route->action, $route->method, $route->roles]);

        SavedRoutes::register();
        $this->get('/about-us')->assertOk()->assertSee('About us');
        $this->get('/saved-routes')->assertSee('<code class="sr-address">/about-us</code>', false);
    }

    public function test_it_is_saved_with_tidy_parameters_and_the_controller_as_the_code_spells_it(): void
    {
        $this->submit(['path' => 'reports', 'controller' => 'admin/auditcontroller', 'function' => 'INDEX', 'parameter' => '{id}, kw?'])->assertSessionHasNoErrors();

        $route = SavedRoute::sole();
        $this->assertSame(['Admin\AuditController', 'index', 'id/kw?'], [$route->controller, $route->action, $route->parameters]);
    }

    public function test_it_refuses_what_would_not_work(): void
    {
        $this->submit(['path' => 'about', 'controller' => 'NoSuchController'])
            ->assertSessionHasErrors(['controller' => "There's no controller named NoSuchController in the controller folders."]);
        $this->submit(['path' => 'about', 'controller' => 'ReportController'])
            ->assertSessionHasErrors(['controller' => 'More than one controller is called ReportController: ReportController and Billing\ReportController. Type the one you mean, with its prefix.']);
        $this->submit(['path' => 'about', 'controller' => 'NotAController', 'function' => 'index'])->assertSessionHasErrors('controller');
        $this->submit(['path' => 'about', 'function' => 'callAction'])
            ->assertSessionHasErrors(['function' => 'PageController has no public function named callAction.']);
        $this->submit(['path' => 'about', 'parameter' => 'id?/kw'])->assertSessionHasErrors('parameter');
        $this->submit(['path' => 'about', 'method' => 'TRACE'])->assertSessionHasErrors('method');
        $this->submit(['path' => 'about us'])->assertSessionHasErrors('path');
        $this->submit(['path' => '../about'])->assertSessionHasErrors('path');
        $this->submit(['path' => 'reports/./about'])->assertSessionHasErrors('path');

        $this->assertSame(0, SavedRoute::count());
    }

    public function test_it_refuses_an_address_or_name_already_taken(): void
    {
        $this->submit(['path' => 'settings'])
            ->assertSessionHasErrors(['path' => 'GET /settings is already a page in this app. Choose another route name or method.']);
        $this->submit(['path' => 'login', 'method' => 'POST'])
            ->assertSessionHasErrors(['path' => 'login is already the name of a page in this app. Choose another route name.']);

        $this->submit(['path' => 'reports', 'parameter' => 'id'])->assertSessionHasNoErrors();
        // Placeholder names don't matter.
        $this->submit(['path' => 'reports', 'parameter' => 'month', 'controller' => 'Admin\AuditController', 'function' => 'index'])
            ->assertSessionHasErrors(['path' => 'GET /reports/{id} is already a route, handled by PageController@about. Choose another route name or method.']);
        // Another method is another route.
        $this->submit(['path' => 'reports', 'parameter' => 'id', 'method' => 'POST'])->assertSessionHasNoErrors();

        $this->assertSame(2, SavedRoute::count());
    }

    public function test_editing_keeps_its_own_address_free_for_itself(): void
    {
        $route = SavedRoute::create(['path' => 'about', 'controller' => 'PageController', 'action' => 'about', 'method' => 'GET']);

        $this->get("/saved-routes/{$route->id}/edit")->assertOk()->assertSee('<h2>Edit route</h2>', false)->assertSee('value="about"', false);
        $this->submit(['path' => 'about', 'function' => 'contact'], $route)
            ->assertSessionHasNoErrors()
            ->assertRedirect("/saved-routes/{$route->id}/edit")
            ->assertSessionHas('saved-routes.message', 'Saved changes to GET /about.');

        $this->assertSame('contact', $route->fresh()->action);
    }

    public function test_a_route_can_be_limited_to_roles_and_opened_to_everyone_again(): void
    {
        $editor = Role::create(['name' => 'Editor']);

        $this->submit(['path' => 'about', 'open_to' => 'roles'])
            ->assertSessionHasErrors(['roles' => 'Tick at least one role, or choose "Everyone who is logged in".']);
        $this->submit(['path' => 'about', 'open_to' => 'roles', 'roles' => ['999']])->assertSessionHasErrors('roles.0');

        $this->submit(['path' => 'about', 'open_to' => 'roles', 'roles' => [(string) $editor->id]])->assertSessionHasNoErrors();
        $route = SavedRoute::sole();
        $this->assertSame([(string) $editor->id], $route->roles);
        $this->get("/saved-routes/{$route->id}/edit")
            ->assertSee('name="open_to" value="roles" checked', false)
            ->assertSee('name="roles[]" value="'.$editor->id.'" checked', false)
            ->assertSeeInOrder(['/about', 'Editor']);

        SavedRoutes::register();
        $this->get('/about')->assertForbidden();

        $this->submit(['path' => 'about', 'open_to' => 'everyone', 'roles' => [(string) $editor->id]], $route)->assertSessionHasNoErrors();
        $this->assertNull($route->fresh()->roles);
        SavedRoutes::register();
        $this->get('/about')->assertOk();
    }

    public function test_search_finds_routes_by_address_or_handler(): void
    {
        SavedRoute::create(['path' => 'about', 'controller' => 'PageController', 'action' => 'about', 'method' => 'GET']);
        SavedRoute::create(['path' => 'audit', 'controller' => 'Admin\AuditController', 'action' => 'index', 'method' => 'GET']);

        $this->get('/saved-routes?search=audit')
            ->assertSee('<code class="sr-address">/<mark>audit</mark></code>', false)
            ->assertDontSee('<code class="sr-address">/about</code>', false);
        $this->get('/saved-routes?search=nothing')->assertSee('Nothing matches “nothing”.');
    }

    public function test_deleting_asks_first_then_the_address_stops_answering(): void
    {
        $route = SavedRoute::create(['path' => 'about', 'controller' => 'PageController', 'action' => 'about', 'method' => 'GET']);

        $this->get("/saved-routes/{$route->id}/delete")->assertOk()->assertSee('Are you sure you want to delete this route?');
        $this->delete("/saved-routes/{$route->id}")
            ->assertRedirect('/saved-routes')
            ->assertSessionHas('saved-routes.message', 'Deleted GET /about.');

        $this->assertSame(0, SavedRoute::count());
        SavedRoutes::register();
        $this->get('/about')->assertNotFound();
    }

    public function test_a_route_whose_code_has_gone_is_marked_on_the_list(): void
    {
        SavedRoute::create(['path' => 'gone', 'controller' => 'GoneController', 'action' => 'index', 'method' => 'GET']);

        $this->get('/saved-routes')->assertSee('Code not found');
    }
}
