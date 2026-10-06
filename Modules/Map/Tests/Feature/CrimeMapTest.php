<?php

namespace Modules\Map\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class CrimeMapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two years of data.gov.my's crime by district file, cut down: the country's totals, a state total, Batu Pahat
     * with some crime types, Nusajaya becoming Iskandar Puteri, Kluang with 2023 only, and Labuan under Sabah.
     */
    private const Figures = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Johor,All,assault,all,2022-01-01,20',
        'Johor,Batu Pahat,assault,all,2022-01-01,10',
        'Johor,Batu Pahat,assault,murder,2022-01-01,1',
        'Johor,Batu Pahat,assault,causing_injury,2022-01-01,9',
        'Johor,Batu Pahat,property,all,2022-01-01,50',
        'Johor,Batu Pahat,property,break_in,2022-01-01,50',
        'Johor,Nusajaya,assault,all,2022-01-01,5',
        'Johor,Nusajaya,property,all,2022-01-01,8',
        'Sabah,W.P. Labuan,assault,all,2022-01-01,3',
        'Sabah,W.P. Labuan,property,all,2022-01-01,30',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,Batu Pahat,assault,all,2023-01-01,12',
        'Johor,Batu Pahat,assault,murder,2023-01-01,2',
        'Johor,Batu Pahat,assault,causing_injury,2023-01-01,10',
        'Johor,Batu Pahat,property,all,2023-01-01,40',
        'Johor,Batu Pahat,property,break_in,2023-01-01,25',
        'Johor,Batu Pahat,property,theft_other,2023-01-01,15',
        'Johor,Iskandar Puteri,assault,all,2023-01-01,7',
        'Johor,Iskandar Puteri,property,all,2023-01-01,9',
        'Johor,Kluang,assault,all,2023-01-01,4',
        'Johor,Kluang,property,all,2023-01-01,6',
        'Sabah,W.P. Labuan,assault,all,2023-01-01,3',
        'Sabah,W.P. Labuan,property,all,2023-01-01,25',
    ];

    /**
     * Write a crime file for the import to read, deleted after the test.
     *
     * @param  list<string>  $lines
     */
    private function crimeFile(array $lines = self::Figures): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * The police district pins in this module's file, as the import reads them.
     *
     * @return list<array<string, string>>
     */
    private function pins(): array
    {
        $lines = array_map(fn (string $line) => str_getcsv($line, escape: ''),
            file(module_path('Map', config('map.crime.districts')), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_shift($lines);

        return array_map(fn (array $line) => array_combine($header, $line), $lines);
    }

    /**
     * Add the Map page and, unless told not to, its crime figures the AMV way, as on the Routes page.
     */
    private function addMapRoutes(bool $crime = true): void
    {
        // Both come with the migrations now (add_dashboard_crime_data_and_map_to_menu); without $crime, the figures'
        // route is taken out, as an administrator could on the Routes page.
        if (! $crime) {
            AppRoute::where('Path', 'map/crime')->delete();
        }

        SavedRoutes::register();
    }

    public function test_the_import_loads_the_figures_and_the_police_district_pins(): void
    {
        $this->artisan('map:import-crime', ['file' => $this->crimeFile()])
            ->expectsOutput('Loaded 26 crime figures for 2022 to 2023, and '.count($this->pins()).' police district pins.')
            ->expectsOutput('Merged Nusajaya into Iskandar Puteri, its name since then.')
            ->doesntExpectOutputToContain('No pin for')
            ->doesntExpectOutputToContain('no label for')
            ->assertSuccessful();

        $this->assertSame(count($this->pins()), PoliceDistrict::count());
        $this->assertSame(26, CrimeStat::count());

        $pin = PoliceDistrict::where('State', 'Sabah')->where('Name', 'W.P. Labuan')->first();
        $this->assertSame(['MY-15', 5.27671, 115.24712], [$pin->Region, $pin->Latitude, $pin->Longitude]);

        // Nusajaya's figures are kept under the name it has now.
        $this->assertSame(5, CrimeStat::where(['District' => 'Iskandar Puteri', 'Year' => 2022, 'Category' => 'assault', 'Type' => 'all'])->value('Crimes'));
        $this->assertFalse(CrimeStat::where('District', 'Nusajaya')->exists());

        // Running it again replaces what was there rather than adding to it.
        $this->artisan('map:import-crime', ['file' => $this->crimeFile()])->assertSuccessful();
        $this->assertSame(26, CrimeStat::count());
        $this->assertSame(count($this->pins()), PoliceDistrict::count());
    }

    public function test_the_import_adds_up_a_renamed_district_under_both_names_and_says_what_the_map_cant_show(): void
    {
        $this->artisan('map:import-crime', ['file' => $this->crimeFile([
            ...self::Figures,
            'Johor,Nusajaya,assault,all,2023-01-01,1',
            'Johor,Nowhere,assault,all,2023-01-01,1',
            'Johor,Batu Pahat,assault,kidnapping,2023-01-01,1',
        ])])
            ->expectsOutputToContain('No pin for Nowhere (Johor), so the map leaves them out.')
            ->expectsOutputToContain('no label for, so its popups leave them out: assault/kidnapping.')
            ->assertSuccessful();

        $this->assertSame(8, CrimeStat::where(['District' => 'Iskandar Puteri', 'Year' => 2023, 'Category' => 'assault', 'Type' => 'all'])->value('Crimes'));
    }

    public function test_the_import_downloads_the_latest_figures_when_given_no_file(): void
    {
        Http::preventStrayRequests();
        Http::fake([config('map.crime.source') => Http::response(implode("\n", self::Figures))]);

        $this->artisan('map:import-crime')
            ->expectsOutput('Downloading '.config('map.crime.source'))
            ->expectsOutputToContain('Loaded 26 crime figures')
            ->assertSuccessful();

        $this->assertSame(26, CrimeStat::count());
    }

    public function test_the_import_changes_nothing_when_it_cant_read_the_figures(): void
    {
        $this->artisan('map:import-crime', ['file' => $this->crimeFile()])->assertSuccessful();

        $this->artisan('map:import-crime', ['file' => $this->crimeFile(['name,crimes', 'Johor,1'])])
            ->expectsOutput("That isn't data.gov.my's crime by district file: its columns should be state, district, category, type, date, crimes.")
            ->assertFailed();

        $this->artisan('map:import-crime', ['file' => $this->crimeFile([...self::Figures, 'Johor,Kluang,assault,all,2024-01-01,many'])])
            ->expectsOutput("Line 28 of the crime file isn't a state, district, category, type, date and number of crimes.")
            ->assertFailed();

        $this->artisan('map:import-crime', ['file' => 'C:/nowhere/crime_district.csv'])
            ->expectsOutput("There's no file at C:/nowhere/crime_district.csv.")
            ->assertFailed();

        Http::fake([config('map.crime.source') => Http::sequence()
            ->push('Service unavailable', 503)
            ->pushFailedConnection('Connection timed out')]);

        $this->artisan('map:import-crime')
            ->expectsOutput("Couldn't download the crime figures from data.gov.my (HTTP 503).")
            ->assertFailed();

        $this->artisan('map:import-crime')
            ->expectsOutput("Couldn't reach data.gov.my: Connection timed out")
            ->assertFailed();

        $this->assertSame(26, CrimeStat::count());
    }

    public function test_the_crime_figures_come_as_json_for_the_latest_year(): void
    {
        $this->signIn();
        $this->addMapRoutes();
        $this->artisan('map:import-crime', ['file' => $this->crimeFile()])->assertSuccessful();

        $this->assertSame(url('/map/crime'), route('map/crime'));

        $response = $this->getJson('/map/crime')
            ->assertOk()
            ->assertJsonPath('year', 2023)
            ->assertJsonPath('years', [2022, 2023])
            ->assertJsonPath('previousYear', 2022)
            ->assertJsonPath('categories', ['assault' => 'Violent crime', 'property' => 'Property crime'])
            ->assertJsonPath('credit', config('map.crime.credit'))
            ->assertJsonPath('about', config('map.crime.about'))
            ->assertJsonPath('national', ['totals' => ['assault' => 900, 'property' => 5200], 'previous' => ['assault' => 1000, 'property' => 5000]]);

        $this->assertSame(['Batu Pahat', 'Iskandar Puteri', 'Kluang', 'W.P. Labuan'], array_column($response->json('districts'), 'name'));

        $response->assertJsonPath('districts.0', [
            'name' => 'Batu Pahat',
            'region' => 'MY-01',
            'lat' => 1.85748,
            'lng' => 102.93981,
            'totals' => ['assault' => 12, 'property' => 40],
            'previous' => ['assault' => 10, 'property' => 50],
            'types' => [
                'assault' => [
                    'murder' => 2, 'rape' => 0, 'causing_injury' => 10, 'robbery_gang_armed' => 0,
                    'robbery_gang_unarmed' => 0, 'robbery_solo_armed' => 0, 'robbery_solo_unarmed' => 0,
                ],
                'property' => [
                    'break_in' => 25, 'theft_vehicle_motorcycle' => 0, 'theft_vehicle_motorcar' => 0,
                    'theft_vehicle_lorry' => 0, 'theft_other' => 15,
                ],
            ],
        ]);

        // Iskandar Puteri's year before is Nusajaya's; Kluang has no year before to compare with.
        $response->assertJsonPath('districts.1.previous', ['assault' => 5, 'property' => 8])
            ->assertJsonPath('districts.2.previous', null);

        // Each region adds up its districts, so Labuan counts on its own rather than in Sabah. Johor has no
        // year before as a whole, since Kluang has none.
        $response->assertJsonPath('regions', [
            'MY-01' => ['districts' => 3, 'totals' => ['assault' => 23, 'property' => 55], 'previous' => null],
            'MY-15' => ['districts' => 1, 'totals' => ['assault' => 3, 'property' => 25], 'previous' => ['assault' => 3, 'property' => 30]],
        ]);
    }

    public function test_the_crime_figures_come_for_the_year_asked_for(): void
    {
        $this->signIn();
        $this->addMapRoutes();
        $this->artisan('map:import-crime', ['file' => $this->crimeFile()])->assertSuccessful();

        $this->getJson('/map/crime?year=2022')
            ->assertOk()
            ->assertJsonPath('year', 2022)
            ->assertJsonPath('previousYear', null)
            ->assertJsonPath('national', ['totals' => ['assault' => 1000, 'property' => 5000], 'previous' => null])
            ->assertJsonPath('districts.*.name', ['Batu Pahat', 'Iskandar Puteri', 'W.P. Labuan'])
            ->assertJsonPath('districts.*.previous', [null, null, null])
            ->assertJsonPath('regions.MY-01', ['districts' => 2, 'totals' => ['assault' => 15, 'property' => 58], 'previous' => null]);

        // A year there are no figures for gives the latest.
        foreach (['1999', 'last'] as $year) {
            $this->getJson('/map/crime?year='.$year)->assertOk()->assertJsonPath('year', 2023);
        }
    }

    public function test_the_crime_figures_need_signing_in_and_are_empty_before_the_import(): void
    {
        $this->signIn();
        $this->addMapRoutes();

        $response = $this->getJson('/map/crime')
            ->assertOk()
            ->assertJsonPath('year', null)
            ->assertJsonPath('years', [])
            ->assertJsonPath('national', null)
            ->assertJsonPath('districts', []);
        // Still an object keyed by region, as the map expects, though there are none.
        $this->assertStringContainsString('"regions":{}', $response->getContent());

        $this->post('/logout');
        $this->getJson('/map/crime')->assertUnauthorized();
        $this->get('/map/crime')->assertRedirect('/login');
    }

    public function test_the_map_page_offers_the_years_there_are_figures_for(): void
    {
        $this->signIn();
        $this->addMapRoutes();
        $this->artisan('map:import-crime', ['file' => $this->crimeFile()])->assertSuccessful();

        $page = $this->get('/map')->assertOk();

        // The cluster plugin, served from this app like Leaflet.
        foreach (['vendor/leaflet-markercluster/MarkerCluster.css', 'vendor/leaflet-markercluster/MarkerCluster.Default.css'] as $stylesheet) {
            $this->assertFileExists(public_path($stylesheet));
            $page->assertSee('<link rel="stylesheet" href="'.versioned_asset($stylesheet).'" />', false);
        }
        $this->assertFileExists(public_path('vendor/leaflet-markercluster/LICENSE.txt'));
        $page->assertSeeInOrder([
            '<script src="'.versioned_asset('vendor/leaflet/leaflet.js').'" defer></script>',
            '<script src="'.versioned_asset('vendor/leaflet-markercluster/leaflet.markercluster.js').'" defer></script>',
            '<script src="'.versioned_asset('modules/map/map.js').'" defer></script>',
        ], false);

        // The map fetches the figures from the saved route; the latest year comes first and is chosen.
        $page->assertSee('data-crime="'.route('map/crime').'"', false)
            ->assertSee('<label for="crime-year">Crime figures for</label>', false)
            ->assertSeeInOrder(['<option value="2023" selected>2023</option>', '<option value="2022"'], false)
            ->assertDontSee('class="crime-note"', false);
    }

    public function test_the_map_page_says_how_to_get_crime_figures_when_it_has_none(): void
    {
        $this->signIn();
        $this->addMapRoutes(crime: false);

        // No route for the figures yet.
        $this->get('/map')
            ->assertOk()
            ->assertSee('Crime figures appear once the <code>map/crime</code> route is added on the Routes page', false)
            ->assertDontSee('data-crime=', false)
            ->assertDontSee('data-crime-year', false);

        // A route, but nothing imported.
        $this->post('/routes', [
            'menu_item_id' => MenuItem::where('Label', 'Map')->value('Id'), 'path' => 'map/crime', 'controller' => 'MapController',
            'function' => 'crime', 'method' => 'GET', 'parameter' => '',
        ])->assertSessionHasNoErrors();
        SavedRoutes::register();

        $this->get('/map')
            ->assertOk()
            ->assertSee('There are no crime figures yet. Load them from data.gov.my with <code>php artisan map:import-crime</code>.', false)
            ->assertDontSee('data-crime=', false)
            ->assertDontSee('data-crime-year', false);
    }

    public function test_every_police_district_pin_is_inside_its_region(): void
    {
        $boundaries = collect(json_decode(file_get_contents(public_path(config('map.boundaries'))), true)['features'])
            ->keyBy(fn (array $feature) => $feature['properties']['shapeISO']);
        $places = [];

        foreach ($this->pins() as $pin) {
            $place = "{$pin['district']} ({$pin['state']})";

            $this->assertArrayHasKey($pin['region'], config('map.states'), $place);
            $this->assertContains($pin['located_at'], ['district police HQ', 'police station', 'town centre'], $place);
            $this->assertTrue($this->inside((float) $pin['longitude'], (float) $pin['latitude'], $boundaries[$pin['region']]['geometry']),
                "{$place}'s pin isn't inside {$pin['region']}.");
            $this->assertArrayNotHasKey($place, $places, "{$place} has two pins.");
            $places[$place] = true;
        }

        $this->assertFileExists(module_path('Map', 'Database/data/police-districts-LICENSE.txt'));

        // A renamed district's pin is under the name the import merges it into.
        foreach (config('map.crime.renamed') as $old => $new) {
            [$state, $district] = explode('|', $old);
            $this->assertArrayHasKey("{$new} ({$state})", $places);
            $this->assertArrayNotHasKey("{$district} ({$state})", $places);
        }
    }

    /**
     * Whether a point is inside a GeoJSON polygon or multipolygon: inside an outer ring and none of its holes.
     *
     * @param  array{type: string, coordinates: array<mixed>}  $geometry
     */
    private function inside(float $longitude, float $latitude, array $geometry): bool
    {
        $polygons = $geometry['type'] === 'Polygon' ? [$geometry['coordinates']] : $geometry['coordinates'];

        foreach ($polygons as $rings) {
            $outer = array_shift($rings);
            $holes = $rings;

            if ($this->insideRing($longitude, $latitude, $outer)
                && ! collect($holes)->contains(fn (array $hole) => $this->insideRing($longitude, $latitude, $hole))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a point is inside a ring, by counting the edges a line east from it crosses.
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function insideRing(float $x, float $y, array $ring): bool
    {
        $inside = false;

        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
