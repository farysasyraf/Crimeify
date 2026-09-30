<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Support\StateCrime;
use Tests\TestCase;

class StateCrimeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * data.gov.my's file, cut down to 2023: the country's and two states' totals, Batu Pahat, and Labuan under Sabah.
     */
    private const Districts = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,All,assault,all,2023-01-01,12',
        'Johor,All,property,all,2023-01-01,40',
        'Sabah,All,assault,all,2023-01-01,50',
        'Sabah,All,property,all,2023-01-01,100',
        'Johor,Batu Pahat,assault,murder,2023-01-01,2',
        'Johor,Batu Pahat,assault,all,2023-01-01,12',
        'Johor,Batu Pahat,property,break_in,2023-01-01,40',
        'Johor,Batu Pahat,property,all,2023-01-01,40',
        'Sabah,W.P. Labuan,assault,all,2023-01-01,3',
        'Sabah,W.P. Labuan,property,all,2023-01-01,25',
    ];

    /**
     * Crime by state: 2023, which data.gov.my has by police district, and 2024, which it doesn't, with robbery as one.
     */
    private const States = [
        'state,category,type,year,crimes',
        'Johor,assault,murder,2023,99',
        'Johor,assault,murder,2024,3',
        'Johor,assault,robbery,2024,10',
        'Johor,property,break_in,2024,30',
        'Johor,property,theft_vehicle_motorcycle,2024,20',
        'Sabah,assault,rape,2024,8',
        'Sabah,property,theft_other,2024,70',
        '"W.P. Kuala Lumpur",assault,robbery,2024,15',
        '"W.P. Kuala Lumpur",property,theft_vehicle_motorcar,2024,45',
    ];

    /**
     * A file for the import to read, deleted after the test.
     *
     * @param  list<string>  $lines
     */
    private function file(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * data.gov.my's figures, then crime by state for the years they don't have, as the import loads them.
     */
    private function importBoth(): void
    {
        config(['map.by_state.file' => $this->file(self::States)]);

        $this->artisan('map:import-crime', ['file' => $this->file(self::Districts)])
            ->expectsOutput('Added crime by state for 2024, which data.gov.my has no police district figures for yet.')
            ->assertSuccessful();
    }

    private function crimes(string $state, string $category, string $type, int $year): ?int
    {
        return CrimeStat::query()->where(['State' => $state, 'District' => 'All', 'Category' => $category, 'Type' => $type, 'Year' => $year])->value('Crimes');
    }

    public function test_the_file_has_the_crime_index_tables_by_state_and_type(): void
    {
        config(['map.by_state.file' => 'Database/data/crime-by-state.csv']);
        $this->assertFileExists(module_path('Map', 'Database/data/crime-by-state-LICENSE.txt'));

        // 14 states, 3 years, 9 types: robbery as one.
        $figures = collect(StateCrime::read());
        $this->assertCount(378, $figures);
        $this->assertSame([2022, 2023, 2024], $figures->pluck('year')->unique()->sort()->values()->all());
        $this->assertSame(['causing_injury', 'murder', 'rape', 'robbery'], $figures->where('category', 'assault')->pluck('type')->unique()->sort()->values()->all());

        // Loaded without data.gov.my's, every year is by state, and Malaysia's add up as the tables have them.
        $this->artisan('map:import-state-crime')->expectsOutput('Added crime by state for 2022, 2023, 2024. The map and dashboard show them now.')->assertSuccessful();
        $this->assertSame([10453, 41991], [$this->crimes('Malaysia', 'assault', 'all', 2023), $this->crimes('Malaysia', 'property', 'all', 2023)]);
        $this->assertSame([11067, 47188], [$this->crimes('Malaysia', 'assault', 'all', 2024), $this->crimes('Malaysia', 'property', 'all', 2024)]);
        $this->assertSame([1168, 4192], [$this->crimes('Johor', 'assault', 'all', 2024), $this->crimes('Johor', 'property', 'all', 2024)]);

        // 2024's vehicle theft, as the file's numbers are, not its headings.
        $this->assertSame(
            [108, 526, 1380],
            [$this->crimes('Johor', 'property', 'theft_vehicle_lorry', 2024), $this->crimes('Johor', 'property', 'theft_vehicle_motorcar', 2024), $this->crimes('Johor', 'property', 'theft_vehicle_motorcycle', 2024)],
        );
    }

    public function test_the_import_adds_crime_by_state_only_for_the_years_without_police_district_figures(): void
    {
        $this->importBoth();

        // 2024 by state, each category's total and Malaysia's added up.
        $this->assertSame([13, 50], [$this->crimes('Johor', 'assault', 'all', 2024), $this->crimes('Johor', 'property', 'all', 2024)]);
        $this->assertSame(10, $this->crimes('Johor', 'assault', 'robbery', 2024));
        $this->assertSame([36, 165, 25], [$this->crimes('Malaysia', 'assault', 'all', 2024), $this->crimes('Malaysia', 'property', 'all', 2024), $this->crimes('Malaysia', 'assault', 'robbery', 2024)]);
        $this->assertSame(0, CrimeStat::where('Year', 2024)->where('District', '!=', 'All')->count());

        // 2023 stays data.gov.my's.
        $this->assertNull($this->crimes('Johor', 'assault', 'murder', 2023));
        $this->assertSame(900, $this->crimes('Malaysia', 'assault', 'all', 2023));
        $this->assertSame([2023], StateCrime::districtYears());
        $this->assertSame([2024], StateCrime::stateOnlyYears());

        // Again, in place of what it added before.
        $rows = CrimeStat::count();
        $this->artisan('map:import-state-crime')->expectsOutput('Added crime by state for 2024. The map and dashboard show it now.')->assertSuccessful();
        $this->assertSame($rows, CrimeStat::count());

        // Once 2024 has police district figures, those are 2024's.
        CrimeStat::create(['State' => 'Johor', 'District' => 'Batu Pahat', 'Category' => 'assault', 'Type' => 'murder', 'Year' => 2024, 'Crimes' => 1]);
        $this->artisan('map:import-state-crime')
            ->expectsOutput('Every year in the file has police district figures already, which are kept. Nothing was added.')
            ->assertSuccessful();
    }

    public function test_the_map_shows_a_year_by_state_with_each_states_totals_and_no_pins(): void
    {
        $this->importBoth();

        $figures = $this->getJson('/public/map/crime')->assertOk()->json();

        // The latest year is by state: no pins, and each region's totals are its state's, compared with 2023's.
        $this->assertSame([2024, 2023, true], [$figures['year'], $figures['previousYear'], $figures['byState']]);
        $this->assertSame([], $figures['districts']);
        $this->assertSame(['totals' => ['assault' => 36, 'property' => 165], 'previous' => ['assault' => 900, 'property' => 5200]], $figures['national']);
        $this->assertSame(
            ['districts' => null, 'totals' => ['assault' => 13, 'property' => 50], 'previous' => ['assault' => 12, 'property' => 40], 'includes' => []],
            $figures['regions']['MY-01'],
        );
        // Sabah's include Labuan's, and Kuala Lumpur's Putrajaya's, which have none of their own.
        $this->assertSame(['Labuan'], $figures['regions']['MY-12']['includes']);
        $this->assertSame(['assault' => 50, 'property' => 100], $figures['regions']['MY-12']['previous']);
        $this->assertSame(['Putrajaya'], $figures['regions']['MY-14']['includes']);
        $this->assertNull($figures['regions']['MY-14']['previous']);
        $this->assertArrayNotHasKey('MY-15', $figures['regions']);
        $this->assertSame(['MY-15' => 'MY-12', 'MY-16' => 'MY-14'], $figures['countedUnder']);
        // Credited to the police's crime index.
        $this->assertSame(['Crime by state: Royal Malaysia Police (PDRM), crime index', null], [$figures['credit'], $figures['about']]);

        // 2023 is by police district, as before.
        $earlier = $this->getJson('/public/map/crime?year=2023')->json();
        $this->assertFalse($earlier['byState']);
        $this->assertSame(['Batu Pahat', 'W.P. Labuan'], array_column($earlier['districts'], 'name'));
        $this->assertSame(config('map.crime.credit'), $earlier['credit']);

        // The page offers 2024 first.
        $this->get('/public/map')->assertSeeInOrder(['<option value="2024" selected>2024</option>', '<option value="2023" >2023</option>'], false);
    }

    public function test_the_dashboard_shows_a_year_by_state(): void
    {
        $this->importBoth();

        $page = $this->get('/public/dashboard')->assertOk()
            ->assertSee("Crime in Malaysia in 2024, from the Royal Malaysia Police's figures for each state.")
            ->assertSee("2024's figures are by state, so Labuan's are in Sabah's and Putrajaya's are in Kuala Lumpur's.")
            ->assertSee("2024's figures by state give robbery as one type, where earlier years have its four kinds.")
            ->assertSee('For 2024, crime by state: Royal Malaysia Police (PDRM), crime index.');

        preg_match('~<script type="application/json" id="dashboard-data">(.*?)</script>~s', $page->getContent(), $data);
        $charts = json_decode($data[1], true);

        // Each region's state, most crime first, named with the regions in it.
        $this->assertSame(
            [['Sabah (with Labuan)', 78], ['Johor', 63], ['Kuala Lumpur (with Putrajaya)', 60]],
            array_map(fn (array $region) => [$region['name'], $region['total']], $charts['regions']),
        );
        // Its crime types, robbery as one.
        $this->assertSame(
            [['Other theft', 70], ['Car theft', 45], ['Break-in', 30], ['Robbery', 25], ['Motorcycle theft', 20], ['All other types', 11]],
            array_map(fn (array $slice) => [$slice['label'], $slice['count']], $charts['types']['slices']),
        );
        // Every year over the years, 2024 last.
        $this->assertSame([2023, 2024], $charts['trend']['years']);

        // 2023 is by police district, as before, with the credit for 2024 still there for the chart over the years.
        $this->get('/public/dashboard?year=2023')
            ->assertSee("from the Royal Malaysia Police's figures for each police district.")
            ->assertDontSee('figures are by state')
            ->assertSee('For 2024, crime by state: Royal Malaysia Police (PDRM), crime index.');
    }

    public function test_the_crime_data_page_edits_only_the_years_with_police_district_figures(): void
    {
        $this->signIn();
        $this->importBoth();

        // 2024 has no figures to list, and adding a figure starts at the year after it.
        $this->get('/crime-data')
            ->assertSee('<option value="2023" >2023</option>', false)
            ->assertDontSee('<option value="2024"', false)
            ->assertSee('id="add-year" name="year" value="2025"', false);

        // A police district's figure for 2024 would have its states' totals added up again from it alone.
        $this->post('/crime-data/store', ['district' => 'Johor|Batu Pahat', 'type' => 'assault.murder', 'year' => 2024, 'crimes' => 1])
            ->assertSessionHasErrors(['year' => "2024's figures are by state only, from the police's crime index, so police district figures can't be added for it."]);
        $this->assertSame(13, $this->crimes('Johor', 'assault', 'all', 2024));
    }
}
