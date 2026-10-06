<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class RegistrationTest extends TestCase
{
    public function test_a_saved_route_answers_at_its_address_and_is_named_after_its_path(): void
    {
        $this->signIn();
        $this->saveRoute(['path' => 'reports']);

        $this->get('/reports')->assertOk()->assertSee('All reports');
        $this->assertSame(url('/reports'), route('reports'));
        $this->assertTrue(SavedRoutes::isSaved(Route::getRoutes()->getByName('reports')));
        $this->assertFalse(SavedRoutes::isSaved(Route::getRoutes()->getByName('settings')));
    }

    public function test_it_takes_its_parameters_and_answers_only_its_method(): void
    {
        $this->signIn();
        $this->saveRoute(['path' => 'reports/show', 'action' => 'show', 'parameters' => 'id']);
        $this->saveRoute(['path' => 'reports/monthly', 'action' => 'monthly', 'parameters' => 'month?']);
        $this->saveRoute(['path' => 'reports', 'action' => 'store', 'method' => 'POST']);

        $this->get('/reports/show/42')->assertOk()->assertSee('Report 42');
        $this->get('/reports/show')->assertNotFound();
        $this->get('/reports/monthly')->assertOk()->assertSee('Monthly report for every month');
        $this->get('/reports/monthly/june')->assertOk()->assertSee('Monthly report for june');
        $this->post('/reports')->assertOk()->assertSee('Report stored');
        $this->get('/reports')->assertMethodNotAllowed();
        $this->assertSame(url('/reports/show/7'), route('reports/show', ['id' => 7]));
    }

    public function test_any_answers_every_method(): void
    {
        $this->signIn();
        $this->saveRoute(['path' => 'anything', 'method' => 'ANY']);

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method}('/anything')->assertOk()->assertSee('All reports');
        }
    }

    public function test_it_needs_a_login_like_every_page_behind_one(): void
    {
        $this->saveRoute(['path' => 'reports']);

        $this->get('/reports')->assertRedirect('/login');
    }

    public function test_the_middleware_it_goes_through_can_be_set(): void
    {
        config(['saved-routes.middleware' => ['web']]);
        $this->saveRoute(['path' => 'reports']);

        $this->get('/reports')->assertOk();
    }

    public function test_it_never_replaces_a_route_in_the_apps_code_or_takes_its_name(): void
    {
        $this->signIn();
        $this->saveRoute(['path' => 'settings']);

        $this->get('/settings')->assertOk()->assertSee('Settings in the code');
        $this->assertSame(1, collect(Route::getRoutes())->filter(fn ($route) => $route->getName() === 'settings')->count());
    }

    public function test_fixed_addresses_come_before_ones_with_parameters_whichever_was_saved_first(): void
    {
        $this->signIn();
        $this->saveRoute(['path' => 'reports', 'action' => 'show', 'parameters' => 'id']);
        $this->saveRoute(['path' => 'reports/summary']);

        $this->get('/reports/summary')->assertOk()->assertSee('All reports');
        $this->get('/reports/9')->assertOk()->assertSee('Report 9');
    }

    public function test_routes_sharing_a_path_share_its_name_and_the_first_takes_it(): void
    {
        $this->signIn();
        $get = $this->saveRoute(['path' => 'reports']);
        $this->saveRoute(['path' => 'reports', 'action' => 'store', 'method' => 'POST']);

        $this->assertSame($get->id, Route::getRoutes()->getByName('reports')->getAction(SavedRoutes::ACTION));
    }

    public function test_adding_them_again_follows_the_table_as_it_is_now(): void
    {
        $this->signIn();
        $route = $this->saveRoute(['path' => 'reports']);

        $route->update(['path' => 'all-reports']);
        SavedRoutes::register();
        $this->get('/all-reports')->assertOk();
        $this->get('/reports')->assertNotFound();
        $this->assertFalse(Route::has('reports'));

        $route->delete();
        SavedRoutes::register();
        $this->get('/all-reports')->assertNotFound();
        $this->get('/settings')->assertOk();
    }

    public function test_a_route_whose_code_has_gone_is_marked_broken(): void
    {
        $this->assertFalse(SavedRoute::make(['path' => 'a', 'controller' => 'ReportController', 'action' => 'index', 'method' => 'GET'])->isBroken());
        $this->assertTrue(SavedRoute::make(['path' => 'a', 'controller' => 'GoneController', 'action' => 'index', 'method' => 'GET'])->isBroken());
        $this->assertTrue(SavedRoute::make(['path' => 'a', 'controller' => 'ReportController', 'action' => 'gone', 'method' => 'GET'])->isBroken());
    }

    public function test_without_its_table_the_app_still_starts_with_no_saved_routes(): void
    {
        Schema::drop('saved_routes');

        SavedRoutes::register();

        $this->assertSame([], collect(Route::getRoutes())->filter(fn ($route) => SavedRoutes::isSaved($route))->all());
        $this->get('/settings')->assertOk();
    }
}
