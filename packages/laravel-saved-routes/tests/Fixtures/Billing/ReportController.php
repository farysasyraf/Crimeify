<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Billing;

use Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers\Controller;

// Shares its class name with the app's ReportController, as two modules' controllers can.
class ReportController extends Controller
{
    public function invoices(): string
    {
        return 'Invoices';
    }
}
