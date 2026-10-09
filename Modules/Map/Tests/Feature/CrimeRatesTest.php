<?php

namespace Modules\Map\Tests\Feature;

use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Map\Entities\Population;
use Tests\TestCase;

class CrimeRatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * data.gov.my's file, cut down: the country, Johor's police districts, and Labuan, which it files under Sabah.
     */
    private const Districts = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Johor,Batu Pahat,assault,all,2022-01-01,10',
        'Johor,Batu Pahat,property,all,2022-01-01,50',
        'Johor,Iskandar Puteri,assault,all,2022-01-01,5',
        'Johor,Iskandar Puteri,property,all,2022-01-01,8',
        'Sabah,W.P. Labuan,assault,all,2022-01-01,3',
        'Sabah,W.P. Labuan,property,all,2022-01-01,30',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,Batu Pahat,assault,all,2023-01-01,12',
        'Johor,Batu Pahat,property,all,2023-01-01,40',
        // A district with its types but no "all" row counts them up, as its popup does.
        'Johor,Iskandar Puteri,assault,murder,2023-01-01,2',
        'Johor,Iskandar Puteri,assault,rape,2023-01-01,5',
        'Johor,Iskandar Puteri,property,all,2023-01-01,9',
        'Sabah,W.P. Labuan,assault,all,2023-01-01,3',
        'Sabah,W.P. Labuan,property,all,2023-01-01,25',
    ];

    /**
     * Crime by state for 2024, which data.gov.my has no police district figures for: Sabah's include Labuan's, and
     * Kuala Lumpur's Putrajaya's.
     */
    private const States = [
        'state,category,type,year,crimes',
        'Johor,assault,murder,2024,3',
        'Johor,property,break_in,2024,30',
        'Sabah,assault,rape,2024,8',
        'Sabah,property,theft_other,2024,70',
        '"W.P. Kuala Lumpur",assault,robbery,2024,15',
        '"W.P. Kuala Lumpur",property,theft_vehicle_motorcar,2024,45',
    ];

    /**
     * DOSM's population by state file, cut down: the totals the import keeps, and the split-up rows it leaves.
     */
    private const People = [
        'state,date,sex,age,ethnicity,population',
        'Johor,2023-01-01,both,overall,overall,4107.2',
        'Johor,2023-01-01,male,overall,overall,2150.0',
        'Johor,2023-01-01,both,0-4,overall,280.1',
        'Johor,2023-01-01,both,overall,bumi_malay,2300.4',
        'W.P. Labuan,2023-01-01,both,overall,overall,99.0',
        'W.P. Kuala Lumpur,2022-01-01,both,overall,overall,1961.2',
        'Pulau Pinang,2023-01-01,both,overall,overall,1772.6',
    ];

    /**
     * A file for an import to read, deleted after the test.
     *
     * @param  list<string>  $lines
     */
    private function file(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * Every region with 100,000 people in each year, but Johor with 200,000 and Labuan with 50,000, so a rate is easy
     * to work out.
     *
     * @param  list<int>  $years
     */
    private function populations(array $years): void
    {
        foreach ($years as $year) {
            foreach (array_keys(config('map.states')) as $region) {
                Population::create(['Region' => $region, 'Year' => $year, 'People' => ['MY-01' => 200000, 'MY-15' => 50000][$region] ?? 100000]);
            }
        }
    }

    /**
     * data.gov.my's figures for 2022 and 2023, then crime by state for 2024, as the import loads them.
     */
    private function importCrime(): void
    {
        config(['map.by_state.file' => $this->file(self::States)]);
        $this->artisan('map:import-crime', ['file' => $this->file(self::Districts)])->assertSuccessful();
    }

    public function test_the_import_loads_each_regions_population_from_the_modules_file(): void
    {
        $this->artisan('map:import-population')
            ->expectsOutput('Loaded the population of 16 regions for 2016 to 2026.')
            ->doesntExpectOutputToContain('No population for')
            ->assertSuccessful();

        $this->assertSame(16 * 11, Population::count());
        $people = fn (string $region, int $year) => Population::where(['Region' => $region, 'Year' => $year])->value('People');
        // In thousands to one decimal in DOSM's file; Labuan and Putrajaya on their own.
        $this->assertSame([4107200, 2005700, 99000, 118800, 3418800], [
            $people('MY-01', 2023), $people('MY-14', 2023), $people('MY-15', 2023), $people('MY-16', 2023), $people('MY-12', 2020),
        ]);

        // Running it again replaces what was there rather than adding to it.
        $this->artisan('map:import-population')->assertSuccessful();
        $this->assertSame(16 * 11, Population::count());
    }

    public function test_the_import_reads_dosms_own_file_keeping_only_the_totals(): void
    {
        $this->artisan('map:import-population', ['file' => $this->file(self::People)])
            ->expectsOutput('Loaded the population of 4 regions for 2022 to 2023.')
            ->expectsOutputToContain('No population for Kedah, Kelantan, Melaka,')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [['MY-01', 2023, 4107200], ['MY-15', 2023, 99000], ['MY-14', 2022, 1961200], ['MY-07', 2023, 1772600]],
            Population::all()->map(fn (Population $population) => [$population->Region, $population->Year, $population->People])->all(),
        );
    }

    public function test_the_import_downloads_dosms_latest_when_asked(): void
    {
        Http::preventStrayRequests();
        Http::fake([config('map.population.source') => Http::response(implode("\n", self::People))]);

        $this->artisan('map:import-population', ['--download' => true])
            ->expectsOutput('Downloading '.config('map.population.source'))
            ->expectsOutput('Loaded the population of 4 regions for 2022 to 2023.')
            ->assertSuccessful();

        $this->assertSame(4, Population::count());
    }

    public function test_the_import_changes_nothing_when_it_cant_read_the_population(): void
    {
        $this->artisan('map:import-population')->assertSuccessful();

        $this->artisan('map:import-population', ['file' => $this->file(['state,year,people', 'Johor,2023,4107200'])])
            ->expectsOutput("That isn't DOSM's population by state file: its columns should be state, date, sex, age, ethnicity, population.")
            ->assertFailed();

        $this->artisan('map:import-population', ['file' => $this->file([...self::People, 'Johor,2024,both,overall,overall,lots'])])
            ->expectsOutput("Line 9 of the population file isn't a state, date and population.")
            ->assertFailed();

        $this->artisan('map:import-population', ['file' => $this->file([...self::People, 'Singapore,2024-01-01,both,overall,overall,6000.0'])])
            ->expectsOutput("Line 9 of the population file has Singapore, which isn't one of Malaysia's states and federal territories.")
            ->assertFailed();

        $this->artisan('map:import-population', ['file' => $this->file(['state,date,sex,age,ethnicity,population', 'Johor,2023-01-01,male,overall,overall,2150.0'])])
            ->expectsOutput('The population file has no totals: rows for both sexes, all ages and all ethnic groups.')
            ->assertFailed();

        $this->artisan('map:import-population', ['file' => 'C:/nowhere/population_state.csv'])
            ->expectsOutput("There's no file at C:/nowhere/population_state.csv.")
            ->assertFailed();

        $this->artisan('map:import-population', ['file' => $this->file(self::People), '--download' => true])
            ->expectsOutput('Give a file or --download, not both.')
            ->assertFailed();

        Http::fake([config('map.population.source') => Http::sequence()
            ->push('Service unavailable', 503)
            ->pushFailedConnection('Connection timed out')]);

        $this->artisan('map:import-population', ['--download' => true])
            ->expectsOutput("Couldn't download the population figures from DOSM (HTTP 503).")
            ->assertFailed();

        $this->artisan('map:import-population', ['--download' => true])
            ->expectsOutput("Couldn't reach DOSM: Connection timed out")
            ->assertFailed();

        $this->assertSame(16 * 11, Population::count());
    }

    public function test_the_crime_figures_give_every_years_crime_per_100000_people(): void
    {
        $this->importCrime();
        $this->populations([2022, 2023, 2024]);

        $rates = $this->getJson('/public/map/crime?year=2022')->assertOk()->json('rates');

        $this->assertSame(100000, $rates['per']);
        $this->assertSame(['all' => 'Violent and property crime', 'assault' => 'Violent crime', 'property' => 'Property crime'], $rates['measures']);
        $this->assertSame(['Population: DOSM (CC BY 4.0)', config('map.population.about')], [$rates['credit'], $rates['about']]);
        // Every year, whichever year was asked for.
        $this->assertSame([2022, 2023, 2024], array_keys($rates['years']));

        // Johor: its police districts' 19 violent and 49 property crimes, Iskandar Puteri's violent counted up from its
        // types, for 200,000 people. Labuan on its own, with its 50,000. (In JSON, a whole number has no decimal point.)
        $this->assertSame(['people' => 200000, 'rates' => ['all' => 34, 'assault' => 9.5, 'property' => 24.5]], $rates['years'][2023]['regions']['MY-01']);
        $this->assertSame(['people' => 50000, 'rates' => ['all' => 56, 'assault' => 6, 'property' => 50]], $rates['years'][2023]['regions']['MY-15']);
        // Regions without crimes that year have no rate, nor Sabah, whose only district here is Labuan's.
        $this->assertSame(['MY-01', 'MY-15'], array_keys($rates['years'][2023]['regions']));

        // The country: its own totals, for everyone in the 16 regions.
        $this->assertSame(['people' => 1650000, 'rates' => ['all' => 369.7, 'assault' => 54.5, 'property' => 315.2]], $rates['years'][2023]['national']);

        // In 2024, by state, Sabah's crimes include Labuan's, so they're divided by both regions' people, and Kuala
        // Lumpur's by its and Putrajaya's. Labuan and Putrajaya have none of their own.
        $this->assertSame(['people' => 150000, 'rates' => ['all' => 52, 'assault' => 5.3, 'property' => 46.7]], $rates['years'][2024]['regions']['MY-12']);
        $this->assertSame(['people' => 200000, 'rates' => ['all' => 30, 'assault' => 7.5, 'property' => 22.5]], $rates['years'][2024]['regions']['MY-14']);
        $this->assertSame(['MY-01', 'MY-12', 'MY-14'], array_keys($rates['years'][2024]['regions']));

        // The shades' limits are the same for every year: round numbers splitting the regions' rates over all of them
        // (16.5, 30, 34, 36.5, 52, 56 and 66) into five bands of about as many each.
        $this->assertSame([31, 35, 46, 55], $rates['scale']['all']);
        $this->assertSame($rates, $this->getJson('/public/map/crime?year=2024')->json('rates'));
    }

    public function test_without_the_population_the_map_has_no_rates_and_says_how_to_load_them(): void
    {
        $this->importCrime();
        $this->signIn();
        SavedRoutes::register();

        $rates = $this->getJson('/map/crime')->assertOk()->json('rates');
        $this->assertSame([[], [], []], array_column($rates['years'], 'regions'));
        $this->assertSame([null, null, null], array_column($rates['years'], 'national'));
        $this->assertSame(['all' => [], 'assault' => [], 'property' => []], $rates['scale']);
        // Still objects keyed by year and region, as the map expects.
        $this->assertStringContainsString('"regions":{}', $this->getJson('/map/crime')->getContent());

        // How to load it is for the app's administrators, not the public.
        $note = "Shading the map by crime per 100,000 people needs each state's population: run <code>php artisan map:import-population</code>.";
        $this->get('/map')->assertOk()->assertSee($note, false);
        $this->get('/public/map')->assertOk()->assertDontSee('map:import-population');

        $this->artisan('map:import-population')->assertSuccessful();
        $this->get('/map')->assertOk()->assertDontSee('map:import-population');
    }
}
