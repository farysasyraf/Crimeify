<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Farysasyraf\SavedRoutes\Tests\TestCase;

/**
 * A fresh install, before the app says who can use the admin page.
 */
class AdminGateTest extends TestCase
{
    public function test_no_one_can_use_the_admin_page_until_the_app_defines_the_gate(): void
    {
        $this->signIn(['admin']);

        $this->get('/saved-routes')->assertForbidden();
        $this->post('/saved-routes', ['path' => 'sneaky', 'controller' => 'PageController', 'function' => 'about', 'method' => 'GET'])->assertForbidden();
        $this->assertSame(0, SavedRoute::count());
    }
}
