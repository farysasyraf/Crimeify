<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers;

class PageController extends Controller
{
    public function about(): string
    {
        return 'About us';
    }

    public function contact(): string
    {
        return 'Contact us';
    }
}
