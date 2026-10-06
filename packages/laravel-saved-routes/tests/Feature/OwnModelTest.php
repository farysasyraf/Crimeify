<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\Tests\Fixtures\LegacyRoute;
use Farysasyraf\SavedRoutes\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * An app that kept its routes in a table of its own before the package: it points saved-routes.model at its model.
 */
class OwnModelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('saved-routes.model', LegacyRoute::class);
    }

    public function test_the_routes_in_its_table_answer_with_their_roles(): void
    {
        LegacyRoute::create(['Address' => 'reports', 'Params' => 'id', 'Controller' => 'ReportController', 'Function' => 'show', 'Verb' => 'GET']);
        LegacyRoute::create(['Address' => 'audit', 'Controller' => 'Admin\AuditController', 'Function' => 'index', 'Verb' => 'GET', 'AdminOnly' => true]);
        SavedRoutes::register();

        $this->signIn();
        $this->get('/reports/5')->assertOk()->assertSee('Report 5');
        $this->get('/audit')->assertForbidden()->assertSee('Only users with the admin role can open this page.');

        $this->signIn(['admin']);
        SavedRoutes::register();
        $this->get('/audit')->assertOk()->assertSee('Audit log');
    }

    public function test_the_package_leaves_out_its_own_table_and_admin_page(): void
    {
        $this->assertFalse(Schema::hasTable('saved_routes'));
        $this->assertFalse(Route::has('saved-routes.index'));
    }

    public function test_it_gets_the_same_checks_as_the_packages_own_model(): void
    {
        LegacyRoute::create(['Address' => 'reports', 'Params' => 'id', 'Controller' => 'ReportController', 'Function' => 'show', 'Verb' => 'GET']);

        $clashing = new LegacyRoute(['Address' => 'reports', 'Params' => 'month', 'Controller' => 'PageController', 'Function' => 'about', 'Verb' => 'ANY']);
        $this->assertSame('GET /reports/{id} is already a route, handled by ReportController@show. Choose another route name or method.', $clashing->clash());
        $this->assertSame('ANY /reports/{month}', $clashing->label());
        $this->assertFalse($clashing->isBroken());
    }
}
