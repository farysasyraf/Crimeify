<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\ControllerLocator;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Billing\ReportController as BillingReportController;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers\Admin\AuditController;
use Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers\ReportController;
use Farysasyraf\SavedRoutes\Tests\TestCase;
use ReflectionClass;

class ControllerLocatorTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function found(string $name): array
    {
        return array_map(fn (ReflectionClass $class) => $class->getName(), SavedRoutes::controllers()->find($name));
    }

    public function test_a_controller_is_found_by_its_name_in_its_folder_its_prefix_or_its_full_class(): void
    {
        $this->assertSame([AuditController::class], $this->found('Admin\AuditController'));
        $this->assertSame([AuditController::class], $this->found('/Admin/AuditController'));
        $this->assertSame([BillingReportController::class], $this->found('Billing\ReportController'));
        $this->assertSame([ReportController::class], $this->found(ReportController::class));

        // The class name alone means every folder's, which the admin page asks to choose between.
        $this->assertSame([ReportController::class, BillingReportController::class], $this->found('ReportController'));
        $this->assertNull(SavedRoutes::controllers()->findOne('ReportController'));
    }

    public function test_only_classes_that_can_be_made_on_the_base_class_inside_a_folder_count(): void
    {
        $this->assertSame([], $this->found('NotAController'));
        $this->assertSame([], $this->found('DraftController'));
        $this->assertSame([], $this->found('Controller'));
        $this->assertSame([], $this->found('NoSuchController'));
        $this->assertSame([], $this->found('Illuminate\Routing\Controller'));
        $this->assertSame([], $this->found('..\..\Fixtures\User'));
        $this->assertSame([], $this->found('Farysasyraf\SavedRoutes\Http\Controllers\SavedRouteController'));
    }

    public function test_a_base_class_that_does_not_exist_lets_nothing_through(): void
    {
        config(['saved-routes.controllers.base_class' => 'App\Http\Controllers\Missing']);
        $this->app->forgetInstance(ControllerLocator::class);

        $this->assertSame([], $this->found('PageController'));
    }

    public function test_only_public_functions_written_in_the_folders_can_be_called(): void
    {
        $locator = SavedRoutes::controllers();
        $report = new ReflectionClass(ReportController::class);

        $this->assertSame('index', $locator->findAction($report, ' index ')?->getName());
        $this->assertSame('show', $locator->findAction($report, 'show')?->getName());

        foreach (['secret', 'make', '__invoke', '__construct', 'callAction', 'middleware', 'getMiddleware', 'missing', 'index()', ''] as $name) {
            $this->assertNull($locator->findAction($report, $name), $name);
        }
    }

    public function test_names_and_classes_go_both_ways(): void
    {
        $locator = SavedRoutes::controllers();

        foreach ([AuditController::class => 'Admin\AuditController', BillingReportController::class => 'Billing\ReportController', ReportController::class => 'ReportController'] as $class => $name) {
            $this->assertSame($name, $locator->nameOf(new ReflectionClass($class)));
            $this->assertSame($class, $locator->classFor($name));
        }
    }

    public function test_options_list_each_controller_with_the_functions_it_can_call(): void
    {
        $this->assertSame([
            'Admin\AuditController' => ['index'],
            'Billing\ReportController' => ['invoices'],
            'PageController' => ['about', 'contact'],
            'ReportController' => ['index', 'show', 'monthly', 'store'],
            'UserController' => ['edit'],
        ], SavedRoutes::controllers()->options());
    }
}
