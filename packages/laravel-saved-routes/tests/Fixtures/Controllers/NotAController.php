<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers;

// In the controllers folder, but not built on the base class.
class NotAController
{
    public function index(): string
    {
        return 'Should never answer';
    }
}
