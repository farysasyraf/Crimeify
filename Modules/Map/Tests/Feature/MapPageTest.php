<?php

namespace Modules\Map\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Add the Map page the AMV way, as on the Routes page: a menu link to group it under, then a route row
     * naming the module's controller. Saved routes reach the router once the app has booted, so add them again here.
     */
    private function addMapRoute(): void
    {
        $module = MenuItem::create(['Label' => 'Map Module', 'SortOrder' => 9, 'Icon' => 'map']);
        $map = MenuItem::create(['Label' => 'Map', 'Url' => '/map', 'SortOrder' => 1, 'ParentId' => $module->Id, 'Icon' => 'place']);

        $this->post('/routes', [
            'menu_item_id' => $map->Id, 'path' => 'map', 'controller' => 'MapController',
            'function' => 'index', 'method' => 'GET', 'parameter' => '',
        ])->assertSessionHasNoErrors();

        AppRoute::registerBehindLogin();
    }

    public function test_the_module_has_no_page_of_its_own_until_the_routes_page_adds_one(): void
    {
        $this->signIn();

        $this->get('/map')->assertNotFound();
    }

    public function test_the_map_page_comes_from_the_routes_page(): void
    {
        $this->signIn();
        $this->addMapRoute();

        // Saved as the module's controller, and named after its path, as in AMV.
        $this->assertSame('Map\MapController', AppRoute::where('Path', 'map')->value('Controller'));
        $this->assertSame(url('/map'), route('map'));

        $this->get('/map')
            ->assertOk()
            ->assertSee('<title>Map · '.config('app.name').'</title>', false)
            ->assertSee('<h1>Crime Visualization Map</h1>', false)
            // The sidebar shows the module with its page, marked as the one open.
            ->assertSeeInOrder([
                '<span class="material-icon side-icon" aria-hidden="true">map</span><span class="side-text">Map Module</span>',
                $this->sideLink('Map', 'page', 'place'),
            ], false);

        $this->post('/logout');
        $this->get('/map')->assertRedirect('/login');
    }

    public function test_the_map_page_shows_malaysias_states_on_a_leaflet_map(): void
    {
        $this->signIn();
        $this->addMapRoute();

        $page = $this->get('/map')->assertOk();

        // Leaflet and the page's own files, served from this app so they work without the internet.
        foreach (['vendor/leaflet/leaflet.css', 'modules/map/map.css'] as $stylesheet) {
            $this->assertFileExists(public_path($stylesheet));
            $page->assertSee('<link rel="stylesheet" href="'.versioned_asset($stylesheet).'" />', false);
        }
        foreach (['vendor/leaflet/leaflet.js', 'modules/map/map.js'] as $script) {
            $this->assertFileExists(public_path($script));
            $page->assertSee('<script src="'.versioned_asset($script).'" defer></script>', false);
        }

        $page->assertSee('id="state-map" data-boundaries="'.versioned_asset('modules/map/malaysia-states.geojson').'"', false);

        // Above the map, the panel with the chosen region's details; beside it, every region in the list by its
        // official name, states first, then federal territories.
        $page->assertSeeInOrder([
            '<section class="card state-panel" aria-labelledby="state-panel-heading">',
            '<h2 id="state-panel-heading">Malaysia</h2>',
            'Choose a state on the map or from the list.',
            'data-show-all hidden',
            '</section>',
            'id="state-map"',
            '<aside class="card state-picker" aria-label="States and federal territories">',
            '<h2 class="state-list-heading">States</h2>',
            'data-state="MY-01" data-name="Johor" data-kind="State"',
            'data-state="MY-04" data-name="Melaka"',
            'data-state="MY-07" data-name="Pulau Pinang"',
            'data-state="MY-13" data-name="Sarawak"',
            '<h2 class="state-list-heading">Federal territories</h2>',
            'data-state="MY-14" data-name="Kuala Lumpur" data-kind="Federal territory"',
            'data-state="MY-15" data-name="Labuan"',
            'data-state="MY-16" data-name="Putrajaya"',
            '</aside>',
        ], false);
        $this->assertSame(16, substr_count($page->getContent(), 'class="state-choice"'));
    }

    public function test_the_boundaries_file_has_exactly_the_regions_the_list_names(): void
    {
        $boundaries = json_decode(file_get_contents(public_path(config('map.boundaries'))), true);

        $this->assertSame('FeatureCollection', $boundaries['type']);

        $codes = collect($boundaries['features'])->map(fn (array $feature) => $feature['properties']['shapeISO'])->sort()->values()->all();
        $this->assertSame(array_keys(config('map.states')), $codes);

        foreach ($boundaries['features'] as $feature) {
            $this->assertContains($feature['geometry']['type'], ['Polygon', 'MultiPolygon']);
        }

        $this->assertFileExists(public_path('modules/map/malaysia-states-LICENSE.txt'));
        $this->assertFileExists(public_path('vendor/leaflet/LICENSE.txt'));
    }

    public function test_the_routes_page_and_manage_menu_find_the_map_module(): void
    {
        $this->signIn();

        $this->assertSame('Map\MapController', AppRoute::controllerName(AppRoute::findController('MapController')));
        $this->assertSame(['index', 'publicIndex', 'crime'], AppRoute::controllerOptions()['Map\MapController']);

        $this->addMapRoute();

        // Once saved, the page is offered in Manage menu's Link list.
        $this->assertContains('/map', AppRoute::linkablePages()['saved']);
    }
}
