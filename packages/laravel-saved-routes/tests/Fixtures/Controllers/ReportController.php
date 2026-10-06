<?php

namespace Farysasyraf\SavedRoutes\Tests\Fixtures\Controllers;

class ReportController extends Controller
{
    public function index(): string
    {
        return 'All reports';
    }

    public function show(string $id): string
    {
        return "Report {$id}";
    }

    public function monthly(?string $month = null): string
    {
        return 'Monthly report for '.($month ?? 'every month');
    }

    public function store(): string
    {
        return 'Report stored';
    }

    protected function secret(): string
    {
        return 'Not a page';
    }

    public static function make(): void {}

    public function __invoke(): string
    {
        return 'Invoked';
    }
}
