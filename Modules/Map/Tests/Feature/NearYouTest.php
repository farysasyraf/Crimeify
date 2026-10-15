<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class NearYouTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The police district pins, as "php artisan map:import-crime" loads them with any figures.
     */
    private function importPins(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, "state,district,category,type,date,crimes\nJohor,Batu Pahat,assault,all,2023-01-01,12\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        $this->artisan('map:import-crime', ['file' => $path])->assertSuccessful();
    }

    /**
     * The JSON a page gives in <script type="application/json" data-district-pins>.
     *
     * @return list<array<string, mixed>>
     */
    private function pins(string $page): array
    {
        preg_match('~<script type="application/json" data-district-pins>(.*?)</script>~s', $page, $json);

        return json_decode($json[1] ?? 'null', true) ?? [];
    }

    public function test_the_map_offers_near_you_with_every_police_district_pin(): void
    {
        $this->importPins();

        $page = $this->get('/public/map')->assertOk()
            // Hidden until map.js finds the browser can give a location.
            ->assertSee('<section class="card near-card" aria-labelledby="near-heading" data-near hidden>', false)
            ->assertSee('Use my location')
            ->assertSee("Your location stays in your browser: it isn't sent to ".config('app.name').' or kept.')
            // Turf loads only when it's first needed, from this app.
            ->assertSee('data-turf="'.versioned_asset('vendor/turf/turf.min.js').'"', false)
            ->assertSee('<link rel="stylesheet" href="'.versioned_asset('vendor/leaflet-locatecontrol/L.Control.Locate.min.css').'" />', false)
            ->assertSeeInOrder([
                '<script src="'.versioned_asset('vendor/leaflet/leaflet.js').'" defer></script>',
                '<script src="'.versioned_asset('vendor/leaflet-locatecontrol/L.Control.Locate.min.js').'" defer></script>',
                '<script src="'.versioned_asset('modules/map/nearby.js').'" defer></script>',
                '<script src="'.versioned_asset('modules/map/map.js').'" defer></script>',
            ], false)
            ->assertDontSee('turf.min.js" defer', false);

        // Every pin, whatever year the figures are for, with where it is and its profile.
        $pins = $this->pins($page->getContent());
        $this->assertCount(PoliceDistrict::count(), $pins);
        $this->assertContains(
            ['name' => 'Batu Pahat', 'region' => 'MY-01', 'lat' => 1.85748, 'lng' => 102.93981, 'profile' => url('/public/areas/johor/batu-pahat')],
            $pins,
        );
    }

    public function test_check_an_area_offers_the_nearest_police_districts_to_compare(): void
    {
        $this->importPins();

        $page = $this->get('/public/areas?lang=ms')->assertOk()
            ->assertSee('data-turf="'.versioned_asset('vendor/turf/turf.min.js').'" data-compare="'.url('/public/areas/compare?lang=ms').'" hidden', false)
            ->assertSee('Guna lokasi saya')
            ->assertSeeInOrder([
                '<script src="'.versioned_asset('modules/map/nearby.js').'" defer></script>',
                '<script src="'.versioned_asset('modules/map/areas-near.js').'" defer></script>',
            ], false);

        $this->assertContains(
            ['name' => 'Batu Pahat', 'region' => 'Johor', 'lat' => 1.85748, 'lng' => 102.93981, 'path' => 'johor/batu-pahat', 'profile' => url('/public/areas/johor/batu-pahat?lang=ms')],
            $this->pins($page->getContent()),
        );
    }

    public function test_without_pins_there_is_nothing_to_be_near(): void
    {
        $this->get('/public/map')->assertOk()->assertDontSee('data-near', false);
    }

    public function test_leaflet_locate_and_turf_are_served_from_this_app_with_their_licences(): void
    {
        foreach (['vendor/leaflet-locatecontrol/L.Control.Locate.min.js', 'vendor/leaflet-locatecontrol/L.Control.Locate.min.css', 'vendor/turf/turf.min.js'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        $this->assertStringContainsString('MIT License', file_get_contents(public_path('vendor/leaflet-locatecontrol/LICENSE.md')));
        $this->assertStringContainsString('MIT License', file_get_contents(public_path('vendor/turf/LICENSE.txt')));
    }
}
