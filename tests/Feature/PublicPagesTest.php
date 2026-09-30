<?php

namespace Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two years of crime figures, cut down: the country's totals and one police district.
     */
    private const Figures = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,assault,murder,2022-01-01,50',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,assault,murder,2023-01-01,60',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,Batu Pahat,assault,all,2023-01-01,12',
        'Johor,Batu Pahat,property,all,2023-01-01,40',
    ];

    private function importFigures(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", self::Figures)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $this->artisan('map:import-crime', ['file' => $path])->assertSuccessful();
    }

    public function test_anyone_can_see_the_dashboard_and_the_map_without_logging_in(): void
    {
        $this->importFigures();

        $this->get('/public')->assertRedirect('/public/dashboard');

        $dashboard = $this->get('/public/dashboard')->assertOk();
        $dashboard->assertSee('<title>Dashboard · '.config('app.name').'</title>', false)
            ->assertSee('<h1>Dashboard</h1>', false)
            ->assertSee('Crime in Malaysia in 2023')
            ->assertSeeInOrder(['<h3>All crime</h3>', '6,100', '<h3>Violent crime</h3>', '900'], false)
            // Choosing a year stays on the public page, and the map it links to is the public one.
            ->assertSee('<form method="get" action="'.route('public.dashboard').'" class="dash-year" data-year-form>', false)
            ->assertSee('<a class="dash-link" href="'.route('public.map').'">Open the map</a>', false);
        $this->get('/public/dashboard?year=2022')->assertOk()->assertSee('Crime in Malaysia in 2022');

        $this->get('/public/map')->assertOk()
            ->assertSee('<title>Map · '.config('app.name').'</title>', false)
            ->assertSee('data-crime="'.route('public.map.crime').'"', false)
            ->assertSee('<option value="2023" selected>2023</option>', false);

        $this->getJson('/public/map/crime')->assertOk()
            ->assertJsonPath('year', 2023)
            ->assertJsonPath('national.totals', ['assault' => 900, 'property' => 5200])
            ->assertJsonPath('districts.0.name', 'Batu Pahat');

        $this->assertGuest();
    }

    public function test_the_public_pages_show_nothing_of_the_app_beyond_them(): void
    {
        $this->importFigures();
        MenuItem::create(['Label' => 'Manage menu', 'Url' => '/menu-items', 'SortOrder' => 1]);

        foreach (['/public/dashboard', '/public/map'] as $address) {
            $page = $this->get($address)->assertOk();

            // Only the two public pages, and a way to log in.
            $page->assertSeeInOrder([
                'aria-label="Pages"', route('public.dashboard'), 'Dashboard', route('public.map'), 'Map',
                '<a class="btn btn-sm btn-primary" href="'.route('login').'">Log in</a>',
            ], false)
                ->assertDontSee('class="sidebar"', false)
                ->assertDontSee('Manage menu')
                ->assertDontSee('Log out')
                ->assertDontSee('MyAppDB');
        }

        $this->get('/public/map')->assertSee('class="public-link active" aria-current="page"', false);
    }

    public function test_with_no_figures_the_public_pages_dont_say_how_to_load_them(): void
    {
        $this->get('/public/dashboard')->assertOk()
            ->assertSee('There are no crime figures yet.')
            ->assertDontSee('php artisan');

        $this->get('/public/map')->assertOk()
            ->assertSee('There are no crime figures yet.')
            ->assertDontSee('php artisan')
            ->assertDontSee('Routes page');
    }

    public function test_someone_logged_in_gets_a_way_back_to_the_app(): void
    {
        $this->signIn();

        $this->get('/public/dashboard')->assertOk()
            ->assertSee('<a class="btn btn-sm" href="'.route('dashboard').'">Open '.config('app.name').'</a>', false)
            ->assertDontSee('>Log in</a>', false);
    }

    public function test_the_apps_own_pages_still_need_a_login(): void
    {
        $this->signIn();
        $module = MenuItem::create(['Label' => 'Map Module', 'SortOrder' => 9, 'Icon' => 'map']);
        $map = MenuItem::create(['Label' => 'Map', 'Url' => '/map', 'SortOrder' => 1, 'ParentId' => $module->Id]);
        foreach (['map' => 'index', 'map/crime' => 'crime'] as $path => $function) {
            $this->post('/routes', [
                'menu_item_id' => $map->Id, 'path' => $path, 'controller' => 'MapController',
                'function' => $function, 'method' => 'GET', 'parameter' => '',
            ])->assertSessionHasNoErrors();
        }
        AppRoute::registerBehindLogin();
        $this->post('/logout');

        foreach (['/dashboard', '/map', '/map/crime', '/crime-data', '/users/create'] as $address) {
            $this->get($address)->assertRedirect('/login');
        }

        // The site's address shows the public dashboard instead of the users list behind it.
        $this->get('/')->assertRedirect('/public/dashboard');
    }

    public function test_the_login_page_leads_to_the_public_pages(): void
    {
        $this->get('/login')->assertOk()->assertSeeInOrder([
            'No account? See the',
            '<a href="'.route('public.dashboard').'">crime dashboard</a>',
            '<a href="'.route('public.map').'">map</a> without logging in.',
        ], false);
    }

    public function test_each_visitor_can_load_the_public_pages_120_times_a_minute(): void
    {
        foreach (range(1, 120) as $request) {
            $this->getJson('/public/map/crime')->assertOk();
        }

        $this->getJson('/public/map/crime')->assertTooManyRequests();
    }
}
