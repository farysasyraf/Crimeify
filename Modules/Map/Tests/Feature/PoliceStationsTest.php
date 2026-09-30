<?php

namespace Modules\Map\Tests\Feature;

use App\Models\AppRoute;
use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\PoliceStation;
use Tests\TestCase;

class PoliceStationsTest extends TestCase
{
    use RefreshDatabase;

    private function station(array $fields = []): PoliceStation
    {
        return PoliceStation::create($fields + [
            'Region' => 'MY-01',
            'District' => 'Batu Pahat',
            'Name' => 'Ibu Pejabat Polis Daerah Batu Pahat',
            'Address' => 'Jalan Kluang, 83000 Batu Pahat, Johor',
            'Phone' => '07 4363300',
        ]);
    }

    public function test_the_police_stations_page_is_in_the_menu_for_admin_only(): void
    {
        // A menu link the migrations add, after the others in its group, shown only to ADMIN.
        $link = MenuItem::firstWhere('Url', '/police-stations');
        $this->assertSame(['Police stations', 'local_police', false], [$link->Label, $link->Icon, (bool) $link->VisibleToEveryone]);
        $this->assertSame(['ADMIN'], $link->roles->pluck('Name')->all());

        $this->signIn();
        $this->get('/police-stations')->assertOk()
            ->assertSee('<h1>Police stations</h1>', false)
            ->assertSee($this->sideLink('Police stations', 'page', 'local_police'), false);
    }

    public function test_without_the_admin_role_no_police_station_page_opens(): void
    {
        $station = $this->station();
        $this->signIn(admin: false);

        $this->get('/dashboard')->assertOk()->assertDontSee('Police stations');
        $this->get('/police-stations')->assertForbidden();
        $this->get('/police-stations/create')->assertForbidden();
        $this->post('/police-stations/store', ['region' => 'MY-01', 'district' => 'Muar', 'name' => 'Sneaky'])->assertForbidden();
        $this->get("/police-stations/edit/{$station->Id}")->assertForbidden();
        $this->put("/police-stations/update/{$station->Id}", ['region' => 'MY-01', 'district' => 'Muar', 'name' => 'Sneaky'])->assertForbidden();
        $this->get("/police-stations/delete/{$station->Id}")->assertForbidden();
        $this->delete("/police-stations/destroy/{$station->Id}")->assertForbidden();

        $this->assertSame('Ibu Pejabat Polis Daerah Batu Pahat', $station->fresh()->Name);
        $this->assertDatabaseCount('PoliceStations', 1);
    }

    public function test_the_list_shows_each_station_and_what_it_is_missing(): void
    {
        $this->signIn();
        $this->station();
        $this->station(['Region' => 'MY-14', 'District' => 'Sentul', 'Name' => 'Ibu Pejabat Polis Daerah Sentul', 'Address' => null, 'Phone' => null]);
        $this->station(['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'Ibu Pejabat Polis Daerah Muar', 'Address' => 'Jalan Petri, 84000 Muar, Johor', 'Phone' => null]);

        // By state, then name.
        $this->get('/police-stations')
            ->assertSee('3 police stations in dbo.PoliceStations')
            ->assertSee('1 station has no address and 2 no phone number yet', false)
            ->assertSeeInOrder([
                'Ibu Pejabat Polis Daerah Batu Pahat', 'Batu Pahat police district', 'Johor', 'Jalan Kluang, 83000 Batu Pahat, Johor', '07 4363300',
                'Ibu Pejabat Polis Daerah Muar', 'Johor', '<span class="muted">Not added yet</span>',
                'Ibu Pejabat Polis Daerah Sentul', 'Kuala Lumpur', '<span class="muted">Not added yet</span>', '<span class="muted">Not added yet</span>',
            ], false);

        // Narrowed by state, a search, or to those missing an address or phone number.
        $this->get('/police-stations?state=MY-14')->assertSee('1 of 3 police stations.')->assertSee('Sentul')->assertDontSee('Batu Pahat police district');
        $this->get('/police-stations?search=petri')->assertSee('Ibu Pejabat Polis Daerah Muar')->assertDontSee('Sentul police district');
        $this->get('/police-stations?missing=1')->assertSee('2 of 3 police stations.')->assertDontSee('Batu Pahat police district');
        $this->get('/police-stations?state=nowhere')->assertSee('3 police stations in dbo.PoliceStations')->assertDontSee('of 3 police stations.');
    }

    public function test_the_list_shows_10_stations_a_page_or_50_100_or_all_as_the_crime_data_page_does(): void
    {
        $this->signIn();
        // 25 stations: 12 in Pulau Pinang (MY-07), then 13 in Perak (MY-08), whose name comes first.
        foreach (range(1, 25) as $n) {
            $this->station(['Region' => $n <= 12 ? 'MY-07' : 'MY-08', 'District' => 'District '.$n, 'Name' => sprintf('Station %02d', $n), 'Address' => null, 'Phone' => null]);
        }
        $rows = fn ($page) => substr_count($page->getContent(), 'police district</span>');
        $numbers = fn ($page) => preg_match_all('~<td class="num muted">(\d+)</td>~', $page->getContent(), $found) ? array_map('intval', $found[1]) : [];

        // 10 at first, with the choices as DataTables' lengthMenu, and the page numbers; by the state's name, so
        // Perak before Pulau Pinang.
        $page = $this->get('/police-stations')
            ->assertSee('1–10 of 25')
            ->assertSeeInOrder([
                '<label for="filter-length">Rows per page</label>',
                '<option value="10" selected>10</option>', '<option value="50" >50</option>',
                '<option value="100" >100</option>', '<option value="-1" >All</option>',
            ], false)
            ->assertSeeInOrder(['Station 13', 'Perak', 'Station 22', 'Perak'], false)
            ->assertDontSee('Pulau Pinang</td>', false)
            ->assertSeeInOrder([
                '<nav class="pager" aria-label="Pages of police stations">',
                'Previous', '<span class="btn btn-sm pager-current" aria-current="page"><span class="visually-hidden">Page </span>1</span>',
                'href="'.url('/police-stations?page=2').'"', 'href="'.url('/police-stations?page=3').'"', 'Next',
            ], false);
        $this->assertSame(10, $rows($page));
        $this->assertSame(range(1, 10), $numbers($page));

        // The numbers go on from page to page.
        $page = $this->get('/police-stations?page=3')->assertSee('21–25 of 25')->assertSee('Station 08')->assertSee('Pulau Pinang</td>', false);
        $this->assertSame(range(21, 25), $numbers($page));

        foreach (['50' => 25, '100' => 25, '-1' => 25, '7' => 10, 'all' => 10] as $length => $count) {
            $this->assertSame($count, $rows($this->get("/police-stations?length={$length}")), "length={$length}");
        }
        $this->get('/police-stations?length=-1')
            ->assertSee('1–25 of 25')
            ->assertSee('<option value="-1" selected>All</option>', false)
            ->assertDontSee('pager-current', false);

        // The choice stays with the filters and the pages, and Clear keeps it.
        $this->get('/police-stations?state=MY-07&length=10')
            ->assertSee('1–10 of 12')
            ->assertSee('href="'.url('/police-stations?state=MY-07&amp;length=10&amp;page=2').'"', false)
            ->assertSee('<a class="btn btn-ghost" href="'.url('/police-stations?length=10').'">Clear</a>', false);

        // Choosing a list's option or ticking the box shows the list at once, as on the Crime data page, refreshing
        // only the list and the Add button, which starts in the state chosen (live-table.js), not the whole page.
        $this->get('/police-stations')
            ->assertSee('<script src="'.versioned_asset('modules/map/police-stations.js').'" defer></script>', false)
            ->assertSee('<script src="'.versioned_asset('js/live-table.js').'" defer></script>', false)
            ->assertSee('data-live-region="stations-add" data-live-params="state"', false)
            ->assertSee('<section class="card station-list" aria-labelledby="stations-heading" data-live-region="stations" data-live-params="state search missing length page">', false);
        $this->assertFileExists(public_path('modules/map/police-stations.js'));
        $this->assertFileExists(public_path('js/live-table.js'));
    }

    public function test_adding_a_police_station(): void
    {
        $this->signIn();
        $this->station();

        // From the list narrowed to a state, the new station starts there.
        $this->get('/police-stations/create?state=MY-01')->assertOk()
            ->assertSee('<option value="MY-01" selected>Johor</option>', false)
            ->assertSee('<label for="district">Police district</label>', false);

        $add = fn (array $fields) => $this->from('/police-stations/create')->post('/police-stations/store', $fields + [
            'region' => 'MY-01', 'district' => 'Muar', 'name' => 'Balai Polis Muar', 'address' => '', 'phone' => '',
        ]);

        $add(['region' => ''])->assertSessionHasErrors(['region' => 'Choose the state or federal territory.']);
        $add(['region' => 'MY-99'])->assertSessionHasErrors(['region' => 'Choose one of the states or federal territories.']);
        $add(['district' => ''])->assertSessionHasErrors(['district' => 'Type the police district.']);
        $add(['name' => 'Ibu Pejabat Polis Daerah Batu Pahat'])->assertSessionHasErrors(['name' => "A police station named 'Ibu Pejabat Polis Daerah Batu Pahat' is already listed in this state."]);
        $add(['phone' => 'call us'])->assertSessionHasErrors(['phone' => 'Use digits, spaces and + ( ) - . only, like 07-436 3300.']);
        $this->assertDatabaseCount('PoliceStations', 1);

        $add([])->assertRedirect('/police-stations')->assertSessionHas('message', 'Added Balai Polis Muar.');
        $this->assertDatabaseHas('PoliceStations', ['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'Balai Polis Muar', 'Address' => null, 'Phone' => null]);

        // The same name is fine in another state.
        $add(['region' => 'MY-02', 'name' => 'Ibu Pejabat Polis Daerah Batu Pahat'])->assertSessionHasNoErrors();
    }

    public function test_editing_a_police_station(): void
    {
        $this->signIn();
        $station = $this->station();

        $this->get("/police-stations/edit/{$station->Id}")->assertOk()
            ->assertSee('<option value="MY-01" selected>Johor</option>', false)
            ->assertSee('value="Batu Pahat"', false)
            ->assertSee('>Jalan Kluang, 83000 Batu Pahat, Johor</textarea>', false)
            ->assertSee('value="07 4363300"', false)
            ->assertSee('data-confirm="Are you sure you want to update this police station?"', false);

        $this->put("/police-stations/update/{$station->Id}", [
            'region' => 'MY-01', 'district' => 'Batu Pahat', 'name' => 'IPD Batu Pahat', 'address' => '  ', 'phone' => '+60 7-436 3300',
        ])->assertRedirect('/police-stations')->assertSessionHas('message', 'Saved changes to IPD Batu Pahat.');

        $this->assertSame(['IPD Batu Pahat', null, '+60 7-436 3300'], [$station->fresh()->Name, $station->fresh()->Address, $station->fresh()->Phone]);
    }

    public function test_deleting_a_police_station(): void
    {
        $this->signIn();
        $station = $this->station();

        $this->get("/police-stations/delete/{$station->Id}")->assertOk()
            ->assertSeeInOrder(['<dt>Name</dt>', 'Ibu Pejabat Polis Daerah Batu Pahat', '<dt>State</dt>', 'Johor', '<dt>Police district</dt>', 'Batu Pahat'], false);

        $this->delete("/police-stations/destroy/{$station->Id}")
            ->assertRedirect('/police-stations')
            ->assertSessionHas('message', 'Deleted Ibu Pejabat Polis Daerah Batu Pahat.');
        $this->assertDatabaseCount('PoliceStations', 0);
    }

    public function test_importing_adds_a_station_for_each_police_district_without_one(): void
    {
        $this->artisan('map:import-stations')->expectsOutput('Added 155 police stations. Edit them on the Police stations page.')->assertSuccessful();

        // From OpenStreetMap: the station's name, and its address and phone number only where it had them.
        $this->assertSame(155, PoliceStation::count());
        $this->assertSame(16, PoliceStation::distinct()->count('Region'));
        $this->assertSame(22, PoliceStation::whereNotNull('Address')->count());
        $this->assertSame(18, PoliceStation::whereNotNull('Phone')->count());
        $this->assertDatabaseHas('PoliceStations', ['Region' => 'MY-01', 'District' => 'Ledang', 'Name' => 'Ibu Pejabat Polis Daerah Tangkak', 'Address' => 'Jalan Payamas, 84900 Tangkak, Johor', 'Phone' => null]);
        // Where the pin is only on a town centre, the district's HQ by its usual name, with nothing made up.
        $this->assertDatabaseHas('PoliceStations', ['Region' => 'MY-01', 'District' => 'Mersing', 'Name' => 'Ibu Pejabat Polis Daerah Mersing', 'Address' => null, 'Phone' => null]);

        // Again: what was edited stays, and a district left without a station gets it back.
        PoliceStation::firstWhere('District', 'Ledang')->update(['Phone' => '06-978 1222']);
        PoliceStation::firstWhere('District', 'Mersing')->delete();
        $this->artisan('map:import-stations')->expectsOutput('Added 1 police station. Edit them on the Police stations page.')->assertSuccessful();
        $this->assertSame('06-978 1222', PoliceStation::firstWhere('District', 'Ledang')->Phone);
        $this->assertSame(155, PoliceStation::count());

        $this->artisan('map:import-stations')->expectsOutput('Every police district already has a station. Nothing was added.')->assertSuccessful();
    }

    public function test_under_the_map_a_state_lists_its_police_stations_with_their_address_and_phone(): void
    {
        $this->station();
        $this->station(['District' => 'Muar', 'Name' => 'Ibu Pejabat Polis Daerah Muar', 'Address' => null, 'Phone' => null]);
        $this->station(['Region' => 'MY-14', 'District' => 'Sentul', 'Name' => 'Ibu Pejabat Polis Daerah Sentul', 'Address' => null, 'Phone' => '+60340482222']);

        // On the public map, as on the app's, which is the same page.
        $page = $this->get('/public/map')->assertOk();

        $page->assertSeeInOrder([
            // Under the map and after the list of regions beside it, so on a phone the list comes first.
            'id="state-map"',
            '<aside class="card state-picker" aria-label="States and federal territories">', '</aside>',
            '<section class="card station-finder" aria-labelledby="station-finder-heading">',
            '<h2 id="station-finder-heading">Find a police station</h2>',
            // Only the states with stations to choose from.
            '<select id="station-state" data-station-state data-placeholder="Choose a state">',
            '<optgroup label="States">', '<option value="MY-01">Johor</option>',
            '<optgroup label="Federal territories">', '<option value="MY-14">Kuala Lumpur</option>',
            // The stations' list waits for a state.
            '<select id="station-choice" data-station-choice data-placeholder="Choose a police station" disabled aria-describedby="station-hint">',
            'Choose a state first.',
            '<div class="station-details" data-station-details aria-live="polite" hidden></div>',
            '<script src="'.versioned_asset('modules/map/station-finder.js').'" defer></script>',
        ], false)->assertDontSee('<option value="MY-02">Kedah</option>', false);
        $this->assertFileExists(public_path('modules/map/station-finder.js'));

        // The stations come with the page, by state and name, with a number phones can call.
        preg_match('~<script type="application/json" data-station-list>(.*?)</script>~s', $page->getContent(), $data);
        $stations = json_decode($data[1], true);
        $this->assertSame(['MY-01', 'MY-14'], array_keys($stations));
        $this->assertSame(['Ibu Pejabat Polis Daerah Batu Pahat', 'Ibu Pejabat Polis Daerah Muar'], array_column($stations['MY-01'], 'name'));
        $this->assertSame([
            'name' => 'Ibu Pejabat Polis Daerah Batu Pahat', 'district' => 'Batu Pahat', 'address' => 'Jalan Kluang, 83000 Batu Pahat, Johor',
            'phone' => '07 4363300', 'call' => 'tel:+6074363300',
            // Where it is on Google Maps and in Waze, by its name and address.
            'maps' => 'https://www.google.com/maps/search/?api=1&query=Ibu%20Pejabat%20Polis%20Daerah%20Batu%20Pahat%2C%20Jalan%20Kluang%2C%2083000%20Batu%20Pahat%2C%20Johor',
            'waze' => 'https://waze.com/ul?q=Ibu%20Pejabat%20Polis%20Daerah%20Batu%20Pahat%2C%20Jalan%20Kluang%2C%2083000%20Batu%20Pahat%2C%20Johor',
        ], array_diff_key($stations['MY-01'][0], ['id' => true]));
        $this->assertSame([null, null, null], [$stations['MY-01'][1]['address'], $stations['MY-01'][1]['phone'], $stations['MY-01'][1]['call']]);
        // Without an address, the map apps search for its name in its state.
        $this->assertSame('https://www.google.com/maps/search/?api=1&query=Ibu%20Pejabat%20Polis%20Daerah%20Muar%2C%20Johor', $stations['MY-01'][1]['maps']);

        // On a phone, the public map asks which app with SweetAlert2, which its layout doesn't have otherwise.
        $page->assertSee('<script src="'.versioned_asset('js/vendor/sweetalert2.all.min.js').'" defer></script>', false);
    }

    public function test_the_app_map_loads_sweetalert2_once(): void
    {
        $this->signIn();
        $module = MenuItem::create(['Label' => 'Map Module', 'SortOrder' => 9]);
        $map = MenuItem::create(['Label' => 'Map', 'Url' => '/map', 'SortOrder' => 1, 'ParentId' => $module->Id]);
        $this->post('/routes', ['menu_item_id' => $map->Id, 'path' => 'map', 'controller' => 'MapController', 'function' => 'index', 'method' => 'GET', 'parameter' => ''])
            ->assertSessionHasNoErrors();
        AppRoute::registerBehindLogin();

        // The app's layout has it already, for its confirm boxes.
        $this->assertSame(1, substr_count($this->get('/map')->assertOk()->getContent(), 'sweetalert2.all.min.js'));
    }

    public function test_without_stations_the_map_says_so(): void
    {
        $this->get('/public/map')->assertOk()
            ->assertSee('No police stations are listed yet.')
            // How to add them is for the app's administrators, not the public.
            ->assertDontSee('map:import-stations')
            ->assertDontSee('data-station-list', false);
    }

    public function test_a_phone_number_becomes_a_number_phones_can_call(): void
    {
        foreach ([
            '07 4363300' => 'tel:+6074363300',
            '+606-5563222' => 'tel:+6065563222',
            '+60 6 647 22 22' => 'tel:+6066472222',
            '0333762222' => 'tel:+60333762222',
            '603-8911 4222' => 'tel:+60389114222',
        ] as $phone => $link) {
            $this->assertSame($link, (new PoliceStation(['Phone' => $phone]))->phoneLink(), $phone);
        }

        $this->assertNull((new PoliceStation)->phoneLink());
    }
}
