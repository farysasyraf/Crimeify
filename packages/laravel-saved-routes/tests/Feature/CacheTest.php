<?php

namespace Farysasyraf\SavedRoutes\Tests\Feature;

use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Farysasyraf\SavedRoutes\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class CacheTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('saved-routes.cache.enabled', true);
    }

    public function test_cached_routes_are_read_once_and_saving_or_deleting_through_the_model_drops_them(): void
    {
        $this->signIn();
        $route = $this->saveRoute(['path' => 'reports']);

        DB::enableQueryLog();
        SavedRoutes::register();
        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'saved_routes')));

        $route->update(['path' => 'all-reports']);
        SavedRoutes::register();
        $this->get('/all-reports')->assertOk();

        $route->delete();
        SavedRoutes::register();
        $this->get('/all-reports')->assertNotFound();
    }

    public function test_a_change_made_straight_to_the_table_needs_the_cache_cleared(): void
    {
        $this->signIn();
        $this->saveRoute(['path' => 'reports']);

        DB::table('saved_routes')->update(['path' => 'all-reports']);
        SavedRoutes::register();
        $this->get('/reports')->assertOk();

        $this->artisan('saved-routes:clear')->assertSuccessful()->expectsOutputToContain('Cleared the cached saved routes.');
        SavedRoutes::register();
        $this->get('/all-reports')->assertOk();
        $this->assertSame('all-reports', SavedRoute::sole()->path);
    }
}
