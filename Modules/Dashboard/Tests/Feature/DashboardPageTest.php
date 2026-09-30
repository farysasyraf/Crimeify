<?php

namespace Modules\Dashboard\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two years of data.gov.my's crime by district file, cut down: the country's totals and some of its crime
     * types, and a few police districts in Johor and Sabah, Labuan among them.
     */
    private const Figures = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,assault,murder,2022-01-01,50',
        'Malaysia,All,assault,causing_injury,2022-01-01,950',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Malaysia,All,property,break_in,2022-01-01,1200',
        'Malaysia,All,property,theft_vehicle_motorcycle,2022-01-01,2000',
        'Malaysia,All,property,theft_vehicle_motorcar,2022-01-01,300',
        'Malaysia,All,property,theft_other,2022-01-01,1500',
        'Johor,Batu Pahat,assault,all,2022-01-01,10',
        'Johor,Batu Pahat,property,all,2022-01-01,50',
        'Sabah,W.P. Labuan,assault,all,2022-01-01,3',
        'Sabah,W.P. Labuan,property,all,2022-01-01,30',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,assault,murder,2023-01-01,60',
        'Malaysia,All,assault,rape,2023-01-01,100',
        'Malaysia,All,assault,causing_injury,2023-01-01,740',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Malaysia,All,property,break_in,2023-01-01,1100',
        'Malaysia,All,property,theft_vehicle_motorcycle,2023-01-01,2100',
        'Malaysia,All,property,theft_vehicle_motorcar,2023-01-01,350',
        'Malaysia,All,property,theft_vehicle_lorry,2023-01-01,50',
        'Malaysia,All,property,theft_other,2023-01-01,1600',
        'Johor,Batu Pahat,assault,all,2023-01-01,12',
        'Johor,Batu Pahat,property,all,2023-01-01,40',
        'Johor,Kluang,assault,all,2023-01-01,4',
        'Johor,Kluang,property,all,2023-01-01,6',
        'Sabah,Kota Kinabalu,assault,all,2023-01-01,20',
        'Sabah,Kota Kinabalu,property,all,2023-01-01,100',
        'Sabah,W.P. Labuan,assault,all,2023-01-01,3',
        'Sabah,W.P. Labuan,property,all,2023-01-01,25',
    ];

    /**
     * Load the figures above with the Map module's import, as "php artisan map:import-crime" does.
     */
    private function importFigures(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", self::Figures)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $this->artisan('map:import-crime', ['file' => $path])->assertSuccessful();
    }

    /**
     * The figures the page gives its charts, from its #dashboard-data.
     *
     * @return array<string, mixed>
     */
    private function chartFigures(TestResponse $page): array
    {
        $this->assertMatchesRegularExpression('~<script type="application/json" id="dashboard-data">(.*?)</script>~s', $page->getContent());
        preg_match('~<script type="application/json" id="dashboard-data">(.*?)</script>~s', $page->getContent(), $match);

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_dashboard_needs_a_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_dashboard_says_how_to_load_figures_when_there_are_none(): void
    {
        $this->signIn();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('<title>Dashboard · '.config('app.name').'</title>', false)
            ->assertSee('<h1>Dashboard</h1>', false)
            ->assertSee('There are no crime figures yet.')
            ->assertSee('<code>php artisan map:import-crime</code>', false)
            ->assertDontSee('data-year-form', false)
            ->assertDontSee('dashboard-data', false);
    }

    public function test_the_dashboard_shows_the_latest_years_figures_compared_with_the_year_before(): void
    {
        $this->signIn();
        $this->importFigures();

        $page = $this->get('/dashboard')->assertOk();

        // The latest year first and chosen; choosing another shows it.
        $page->assertSee('Crime in Malaysia in 2023')
            ->assertSee('<form method="get" action="'.route('dashboard').'" class="dash-year" data-year-form>', false)
            ->assertSeeInOrder(['<option value="2023" selected>2023</option>', '<option value="2022"'], false);

        // Each card's count, then how it compares, in words for screen readers. Up is more crime.
        $page->assertSeeInOrder([
            '<h3>All crime</h3>', '6,100', '▲ 2%', 'Up 2% from 6,000 in 2022',
            '<h3>Violent crime</h3>', '900', '▼ 10%', 'Down 10% from 1,000 in 2022',
            '<h3>Property crime</h3>', '5,200', '▲ 4%', 'Up 4% from 5,000 in 2022',
            '<h3>Murder</h3>', '60', '▲ 20%', 'Up 20% from 50 in 2022',
            '<h3>Break-ins</h3>', '1,100', '▼ 8%', 'Down 8% from 1,200 in 2022',
            '<h3>Vehicle theft</h3>', '2,500', '▲ 9%', 'Up 9% from 2,300 in 2022',
        ], false);
        $page->assertSee('<span class="dash-change dash-down" title="Down 10% from 1,000 in 2022">', false);

        // Chart.js and the page's own files, served from this app.
        foreach (['vendor/chartjs/chart.umd.min.js', 'modules/dashboard/dashboard.js'] as $script) {
            $this->assertFileExists(public_path($script));
            $page->assertSee('<script src="'.versioned_asset($script).'" defer></script>', false);
        }
        $this->assertFileExists(public_path('vendor/chartjs/LICENSE.md'));
        $this->assertFileExists(public_path('modules/dashboard/dashboard.css'));
        $page->assertSee('<link rel="stylesheet" href="'.versioned_asset('modules/dashboard/dashboard.css').'" />', false);

        $charts = $this->chartFigures($page);

        $this->assertSame(2023, $charts['year']);
        $this->assertSame([2022, 2023], $charts['trend']['years']);
        $this->assertSame([
            ['label' => 'Violent crime', 'colour' => '#ff8a80', 'counts' => [1000, 900]],
            ['label' => 'Property crime', 'colour' => '#3aaaf2', 'counts' => [5000, 5200]],
        ], $charts['trend']['series']);

        // Each region's districts added up, most crime first, with Labuan on its own rather than in Sabah.
        $this->assertSame([
            ['code' => 'MY-12', 'name' => 'Sabah', 'short' => 'SBH', 'totals' => ['assault' => 20, 'property' => 100], 'total' => 120],
            ['code' => 'MY-01', 'name' => 'Johor', 'short' => 'JHR', 'totals' => ['assault' => 16, 'property' => 46], 'total' => 62],
            ['code' => 'MY-15', 'name' => 'Labuan', 'short' => 'LBN', 'totals' => ['assault' => 3, 'property' => 25], 'total' => 28],
        ], $charts['regions']);

        // The five largest crime types, then the rest added up.
        $this->assertSame(6100, $charts['types']['total']);
        $this->assertSame([
            ['label' => 'Motorcycle theft', 'count' => 2100, 'share' => 34.4, 'colour' => '#3aaaf2'],
            ['label' => 'Other theft', 'count' => 1600, 'share' => 26.2, 'colour' => '#ff8a80'],
            ['label' => 'Break-in', 'count' => 1100, 'share' => 18, 'colour' => '#7fd8a4'],
            ['label' => 'Causing injury', 'count' => 740, 'share' => 12.1, 'colour' => '#f5b041'],
            ['label' => 'Car theft', 'count' => 350, 'share' => 5.7, 'colour' => '#b39ddb'],
            ['label' => 'All other types', 'count' => 210, 'share' => 3.4, 'colour' => '#6b7785'],
        ], $charts['types']['slices']);

        // The same figures as text beside the charts, and the crime by type list.
        $page->assertSeeInOrder(['<caption>Crime in Malaysia each year</caption>', '<th scope="row">2022</th><td>1,000</td><td>5,000</td>'], false)
            ->assertSeeInOrder(['<caption>Crime by state in 2023, most first</caption>', '<th scope="row">Sabah</th><td>20</td><td>100</td><td>120</td>'], false)
            ->assertSeeInOrder(['Motorcycle theft', '34.4%', '2,100', 'All other types', '3.4%', '210'])
            ->assertSee('Crime data: PDRM &amp; DOSM (CC BY 4.0), from <a href="https://data.gov.my/data-catalogue/crime_district">data.gov.my</a>', false);
    }

    public function test_the_dashboard_shows_the_year_asked_for(): void
    {
        $this->signIn();
        $this->importFigures();

        $page = $this->get('/dashboard?year=2022')->assertOk();

        // No year before 2022 to compare with.
        $page->assertSee('Crime in Malaysia in 2022')
            ->assertSee('<option value="2022" selected>2022</option>', false)
            ->assertSeeInOrder(['<h3>All crime</h3>', '6,000', '<h3>Violent crime</h3>', '1,000'], false)
            ->assertDontSee('dash-change', false);

        $charts = $this->chartFigures($page);
        $this->assertSame(2022, $charts['year']);
        $this->assertSame(['MY-01', 'MY-15'], array_column($charts['regions'], 'code'));
        $this->assertSame(6000, $charts['types']['total']);

        // A year there are no figures for shows the latest.
        foreach (['1999', 'last'] as $year) {
            $this->assertSame(2023, $this->chartFigures($this->get('/dashboard?year='.$year)->assertOk())['year']);
        }
    }

    public function test_the_dashboard_is_the_home_page_and_can_be_a_menu_link(): void
    {
        $this->signIn();

        $this->assertSame(url('/dashboard'), route('dashboard'));

        // The sidebar's logo leads to it, and Manage menu offers it as a page to link to: a route saved on the Routes
        // page, where it can be managed like the rest.
        $this->get('/dashboard')->assertSee('<a class="side-brand" href="'.route('dashboard').'">', false);
        $this->assertContains('/dashboard', AppRoute::linkablePages()['saved']);
        $this->assertSame('Dashboard\DashboardController', AppRoute::where('Path', 'dashboard')->value('Controller'));

        MenuItem::create(['Label' => 'Dashboard', 'Url' => '/dashboard', 'SortOrder' => 0, 'Icon' => 'dashboard']);
        $this->get('/dashboard')->assertSee($this->sideLink('Dashboard', 'page', 'dashboard'), false);
    }

    public function test_the_crime_by_state_card_links_to_the_map_once_there_is_one(): void
    {
        $this->signIn();
        $this->importFigures();

        $this->get('/dashboard')->assertDontSee('Open the map');

        $module = MenuItem::create(['Label' => 'Map Module', 'SortOrder' => 9, 'Icon' => 'map']);
        $map = MenuItem::create(['Label' => 'Map', 'Url' => '/map', 'SortOrder' => 1, 'ParentId' => $module->Id, 'Icon' => 'place']);
        $this->post('/routes', [
            'menu_item_id' => $map->Id, 'path' => 'map', 'controller' => 'MapController',
            'function' => 'index', 'method' => 'GET', 'parameter' => '',
        ])->assertSessionHasNoErrors();
        AppRoute::registerBehindLogin();

        $this->get('/dashboard')->assertSee('<a class="dash-link" href="'.route('map').'">Open the map</a>', false);
    }
}
