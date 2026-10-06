<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers\Admin;

use Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers\Controller;

class AuditController extends Controller
{
    public function index(): string
    {
        return 'Audit log';
    }
}
