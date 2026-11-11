<?php

namespace Modules\Map\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_database_has_the_map_page_under_a_map_module_link(): void
    {
        // From the migrations (add_dashboard_crime_data_and_map_to_menu), as MyAppDB has them: the page and the
        // figures its pins load, both open to everyone who is logged in, like the public map.
        $routes = AppRoute::with('menuItem')->whereIn('Path', ['map', 'map/crime'])->orderBy('Path')->get();
        $this->assertSame(['Map\MapController@index', 'Map\MapController@crime'], $routes->map->handler()->all());
        $this->assertSame([true, true], $routes->pluck('OpenToEveryone')->all());
        $this->assertSame(['Map', 'Map'], $routes->map(fn (AppRoute $route) => $route->menuItem->Label)->all());

        $map = MenuItem::firstWhere('Url', '/map');
        $this->assertSame(['Map Module', null, 'map'], [MenuItem::find($map->ParentId)->Label, MenuItem::find($map->ParentId)->Url, MenuItem::find($map->ParentId)->Icon]);
        $this->assertSame('place', $map->Icon);
        $this->assertTrue($map->VisibleToEveryone);
    }

    public function test_the_map_page_goes_once_its_route_is_deleted_on_the_routes_page(): void
    {
        $this->signIn();
        $this->get('/map')->assertOk();

        $this->delete('/routes/'.AppRoute::firstWhere('Path', 'map')->Id)->assertSessionHasNoErrors();
        SavedRoutes::register();

        $this->get('/map')->assertNotFound();
    }

    public function test_the_map_page_comes_from_the_routes_page(): void
    {
        $this->signIn();

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

    public function test_the_street_map_is_crimeifys_own_style_until_another_is_set(): void
    {
        $this->signIn();

        $this->get('/map')->assertOk()->assertSee('data-style="'.versioned_asset('modules/map/style.json').'"', false);

        // Crimeify's style: OpenFreeMap's vector tiles of OpenStreetMap, which need no key, with their credits.
        $style = json_decode(file_get_contents(public_path('modules/map/style.json')), true);
        $this->assertSame([8, 'https://tiles.openfreemap.org/planet'], [$style['version'], $style['sources']['openmaptiles']['url']]);
        $this->assertStringContainsString('OpenStreetMap', file_get_contents(public_path('modules/map/style-LICENSE.txt')));

        // Another style, such as a tile provider's, by its address, written into the page as a data attribute.
        config(['map.style' => 'https://tiles.example.com/style.json?key=abc&lang=ms']);
        $this->get('/map')->assertOk()->assertSee('data-style="https://tiles.example.com/style.json?key=abc&amp;lang=ms"', false);
    }

    public function test_the_map_page_shows_malaysias_states_on_a_maplibre_map(): void
    {
        $this->signIn();

        $page = $this->get('/map')->assertOk();

        // MapLibre GL JS and the page's own files, served from this app. map.js loads MapLibre as a module, and
        // MapLibre its worker, from beside it.
        foreach (['vendor/maplibre-gl/maplibre-gl.css', 'modules/map/map.css'] as $stylesheet) {
            $this->assertFileExists(public_path($stylesheet));
            $page->assertSee('<link rel="stylesheet" href="'.versioned_asset($stylesheet).'" />', false);
        }
        $page->assertSee('<script src="'.versioned_asset('modules/map/map.js').'" defer></script>', false)
            ->assertSee('data-maplibre="'.versioned_asset('vendor/maplibre-gl/maplibre-gl.mjs').'"', false)
            ->assertDontSee('vendor/leaflet/', false);
        $this->assertFileExists(public_path('vendor/maplibre-gl/maplibre-gl-worker.mjs'));
        // Browsers run a module only when it's served as JavaScript.
        $this->assertStringContainsString('AddType text/javascript .mjs', file_get_contents(public_path('.htaccess')));

        $page->assertSee('id="state-map" data-boundaries="'.versioned_asset('modules/map/malaysia-states.geojson').'"', false);

        // The map fills the page, with cards over it, as in the Crimeify mockup: the search at the top; and down the
        // left, the panel with the chosen region's details, every region by its official name, states first, then
        // federal territories, and "Find a police station", all before the map and its many pins.
        $page->assertSee('<main class="main main-full">', false);
        $page->assertSeeInOrder([
            '<div class="page-head visually-hidden">', '<h1>Crime Visualization Map</h1>', '</div>',
            '<div class="map-stage">',
            '<form class="map-search" role="search" data-map-search data-map-cover="top" data-places="'.route('public.places').'" data-places-about="https://photon.komoot.io" hidden>',
            '<label for="map-search" class="visually-hidden">Search the map</label>',
            '<input type="search" id="map-search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="map-search-results"',
            'placeholder="Search a place, police district or station"',
            '<button type="submit" class="map-search-go" aria-label="Search">',
            '<ul class="map-search-results" id="map-search-results" role="listbox" aria-label="Search the map" hidden></ul>',
            '</form>',
            '<div class="map-side" data-map-cover="left">',
            '<section class="card state-panel" aria-labelledby="state-panel-heading">',
            '<h2 id="state-panel-heading">Malaysia</h2>',
            'Choose a state on the map or from the list.',
            'data-show-all hidden',
            '</section>',
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
            '<section class="card station-finder" aria-labelledby="station-finder-heading">',
            '</section>',
            '</div>',
            'id="state-map"',
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
        $this->assertStringContainsString('BSD', file_get_contents(public_path('vendor/maplibre-gl/LICENSE.txt')));
    }

    public function test_the_routes_page_and_manage_menu_find_the_map_module(): void
    {
        $this->signIn();

        $this->assertSame('Map\MapController', SavedRoutes::controllers()->nameOf(SavedRoutes::controllers()->findOne('MapController')));
        $this->assertSame(['index', 'publicIndex', 'publicDistrict', 'districtPreview', 'crime'], SavedRoutes::controllers()->options()['Map\MapController']);

        // Once saved, the page is offered in Manage menu's Link list.
        $this->assertContains('/map', AppRoute::linkablePages()['saved']);
    }
}
