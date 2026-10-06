<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers;

// Can't be made, so it can't handle a route.
abstract class DraftController extends Controller
{
    public function index(): string
    {
        return 'Draft';
    }
}
