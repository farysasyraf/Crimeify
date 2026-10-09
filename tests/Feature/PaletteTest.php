<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use App\Support\Palette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;
use Tests\TestCase;

class PaletteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The results under one heading for what's typed, or null if there are none.
     *
     * @return list<array<string, string>>|null
     */
    private function found(string $term, string $heading): ?array
    {
        return collect($this->getJson('/palette?q='.urlencode($term))->assertOk()->json('groups'))->firstWhere('heading', $heading)['results'] ?? null;
    }

    public function test_every_page_of_the_app_has_search_and_its_shortcut(): void
    {
        $this->signIn();

        $this->get('/profile')->assertOk()
            ->assertSee('<button type="button" class="palette-open" data-palette="'.route('palette').'" aria-label="Search" aria-keyshortcuts="Control+K Meta+K" hidden>', false)
            ->assertSee('<script src="'.versioned_asset('js/palette.js').'" defer></script>', false);
    }

    public function test_with_nothing_typed_it_lists_the_pages_the_user_can_open_in_the_menus_order(): void
    {
        // A link to a page that isn't there, and one to another site.
        MenuItem::create(['Label' => 'Old reports', 'Url' => '/old-reports', 'SortOrder' => 90]);
        MenuItem::create(['Label' => 'PDRM', 'Url' => 'https://www.rmp.gov.my', 'SortOrder' => 91]);
        $this->signIn(admin: false);

        $groups = $this->getJson('/palette')->assertOk()->json('groups');
        $this->assertSame(['Pages'], array_column($groups, 'heading'));
        $pages = $groups[0]['results'];
        $labels = array_column($pages, 'label');

        // In the menu's order, with what's above them and their icons, then the user's profile.
        $this->assertSame(['Dashboard', 'Users', 'Roles', 'Manage menu', 'Map', 'PDRM', 'My profile'], $labels);
        $this->assertContains(['label' => 'Map', 'about' => 'Map Module', 'url' => url('/map'), 'icon' => 'place'], $pages);
        $this->assertContains(['label' => 'PDRM', 'about' => 'Menu', 'url' => 'https://www.rmp.gov.my', 'icon' => MenuItem::DefaultIcon], $pages);
        $this->assertSame(['label' => 'My profile', 'about' => 'Your details, photo and password', 'url' => route('profile'), 'icon' => 'account_circle'], end($pages));

        // Not the pages only administrators can open, though the menu has them, nor a link to no page.
        foreach (['Add user', 'Crime data', 'Manage routes', 'Old reports'] as $hidden) {
            $this->assertNotContains($hidden, $labels);
        }

        // Nor anything else until something's typed.
        User::create(['Name' => 'Ada Lovelace', 'Email' => 'ada@example.com']);
        $this->assertCount(1, $this->getJson('/palette')->json('groups'));
    }

    public function test_an_administrator_has_the_pages_only_they_can_open_too(): void
    {
        $this->signIn();

        $labels = array_column($this->found('', 'Pages'), 'label');

        foreach (['Add user', 'Crime data', 'Manage routes', 'Police stations', 'Users', 'My profile'] as $page) {
            $this->assertContains($page, $labels);
        }
    }

    public function test_it_finds_users_by_name_username_or_email_for_whoever_can_edit_them(): void
    {
        $me = $this->signIn();
        $ada = User::create(['Name' => 'Ada Lovelace', 'Email' => 'ada@example.com']);
        User::create(['Name' => 'Grace Hopper', 'Email' => 'grace@navy.example']);

        $this->assertSame(
            [['label' => 'Ada Lovelace', 'about' => "{$ada->Username} · ada@example.com", 'url' => url("/users/edit/{$ada->Id}"), 'icon' => 'person']],
            $this->found('lovel', 'Users'),
        );
        // By email too, after those found by name.
        $this->assertSame(['Grace Hopper'], array_column($this->found('navy', 'Users'), 'label'));
        User::create(['Name' => 'Bob', 'Email' => 'bob@ada.example']);
        $this->assertSame(['Ada Lovelace', 'Bob'], array_column($this->found('ada', 'Users'), 'label'));

        // Users/edit starts limited to ADMIN on the Routes page, so without it, no users.
        $me->roles()->detach();
        $me->unsetRelation('roles');
        $this->assertNull($this->found('lovel', 'Users'));
    }

    public function test_it_finds_police_districts_and_stations_on_the_map_best_match_first(): void
    {
        $this->signIn(admin: false);
        foreach (['Batu Pahat' => 'MY-01', 'Kota Batu' => 'MY-02', 'Rambatu' => 'MY-03', 'Abatu' => 'MY-04', 'Muar' => 'MY-01'] as $name => $region) {
            PoliceDistrict::create(['State' => config("map.states.{$region}.name"), 'Name' => $name, 'Region' => $region, 'Latitude' => 2, 'Longitude' => 102]);
        }
        $station = PoliceStation::create(['Region' => 'MY-01', 'District' => 'Batu Pahat', 'Name' => 'IPD Batu Pahat', 'Address' => 'Jalan Bakau Condong', 'Phone' => '07-434 4444']);

        // One that starts with it, one with a word that does, then those with it anywhere, not in the alphabet's order.
        $districts = $this->found('batu', 'Police districts');
        $this->assertSame(['Batu Pahat', 'Kota Batu', 'Abatu', 'Rambatu'], array_column($districts, 'label'));
        $this->assertSame(
            ['label' => 'Batu Pahat', 'about' => 'Police district in Johor', 'url' => route('map', ['district' => 'johor/batu-pahat']), 'icon' => 'location_on'],
            $districts[0],
        );
        $this->assertSame(
            [['label' => 'IPD Batu Pahat', 'about' => 'Police station in Batu Pahat, Johor', 'url' => route('map', ['station' => $station->Id]), 'icon' => 'local_police']],
            $this->found('batu', 'Police stations'),
        );

        // In the order the sources come: pages, users, police districts, stations.
        $this->assertSame(['Pages', 'Police districts', 'Police stations'], array_column($this->getJson('/palette?q=a')->json('groups'), 'heading'));

        // % and _ are themselves, not any text.
        $this->assertNull($this->found('%', 'Police districts'));
    }

    public function test_it_needs_a_login(): void
    {
        $this->getJson('/palette?q=a')->assertUnauthorized();
    }

    public function test_anything_but_text_is_as_if_nothing_was_typed(): void
    {
        $this->signIn();

        $this->assertSame($this->getJson('/palette')->json(), $this->getJson('/palette?q[]=a')->assertOk()->json());
    }

    public function test_a_match_ranks_by_where_it_is_ignoring_case_and_accents(): void
    {
        $this->assertSame([0, 1, 2, null, 0], [
            Palette::rank('Batu Pahat', 'batu'), Palette::rank('Kota Batu', 'BATU'), Palette::rank('Rambatu', 'batu'),
            Palette::rank('Muar', 'batu'), Palette::rank('Pulau Pinang', ''),
        ]);
        $this->assertSame(0, Palette::rank('Café', 'cafe'));
    }
}
