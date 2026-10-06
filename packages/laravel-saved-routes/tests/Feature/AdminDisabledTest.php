<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * An app that manages saved routes some other way, with the admin page turned off.
 */
class AdminDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('saved-routes.admin.enabled', false);
    }

    public function test_the_admin_page_is_not_there(): void
    {
        $this->signIn(['admin']);

        $this->assertFalse(Route::has('saved-routes.index'));
        $this->get('/saved-routes')->assertNotFound();
    }
}
