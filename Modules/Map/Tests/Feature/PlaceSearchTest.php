<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Map\Support\PlaceSearch;
use Tests\TestCase;

class PlaceSearchTest extends TestCase
{
    /**
     * A place as Photon gives it: a GeoJSON feature with OpenStreetMap's names for where it is.
     *
     * @param  array<string, string>  $where
     * @return array<string, mixed>
     */
    private function feature(string $name, float $lng, float $lat, array $where, string $country = 'MY'): array
    {
        return ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]], 'properties' => ['name' => $name, 'countrycode' => $country] + $where];
    }

    private function photon(array $features, int $status = 200): void
    {
        Http::preventStrayRequests();
        Http::fake([config('map.places.source').'*' => Http::response(['type' => 'FeatureCollection', 'features' => $features], $status)]);
    }

    public function test_it_finds_places_in_malaysia_with_where_each_one_is(): void
    {
        $this->photon([
            $this->feature('Taman Melawati', 101.75196, 3.21340, ['district' => 'Ulu Kelang', 'county' => 'Gombak', 'state' => 'Selangor']),
            // The same place again, as Photon gives a road in parts, once.
            $this->feature('Taman Melawati', 101.75249, 3.20825, ['district' => 'Ulu Kelang', 'county' => 'Gombak', 'state' => 'Selangor', 'postcode' => '53300']),
            // Another of the same name elsewhere, kept, and named by where it is.
            $this->feature('Taman Melawati', 103.06529, 2.00558, ['county' => 'Batu Pahat', 'state' => 'Johor']),
            // Singapore is inside the box Photon looks in, but not Malaysia.
            $this->feature('Taman Melawati', 103.8, 1.35, ['city' => 'Singapore'], 'SG'),
            // A place named as its own district says where it is without saying its name again.
            $this->feature('Kajang', 101.79, 2.99, ['city' => 'Kajang', 'county' => 'Hulu Langat', 'state' => 'Selangor']),
        ]);

        $this->assertSame(['places' => [
            ['name' => 'Taman Melawati', 'about' => 'Ulu Kelang, Gombak, Selangor', 'lat' => 3.2134, 'lng' => 101.75196],
            ['name' => 'Taman Melawati', 'about' => 'Batu Pahat, Johor', 'lat' => 2.00558, 'lng' => 103.06529],
            ['name' => 'Kajang', 'about' => 'Hulu Langat, Selangor', 'lat' => 2.99, 'lng' => 101.79],
        ], 'error' => false], PlaceSearch::search('  Taman   Melawati '));

        // Asked once, around Malaysia, without railway and bus lines, stops or car park roads, as this app.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'q=Taman+Melawati')
            && str_contains($request->url(), 'bbox=99.6%2C0.8%2C119.3%2C7.4')
            && str_contains($request->url(), '&osm_tag=%21railway&osm_tag=%21route&osm_tag=%21public_transport')
            && str_contains($request->url(), 'osm_tag=%21highway%3Abus_stop')
            && str_starts_with($request->header('User-Agent')[0], config('app.name').' ('));
    }

    public function test_a_search_is_kept_so_it_isnt_asked_again(): void
    {
        $this->photon([$this->feature('Kajang', 101.79, 2.99, ['state' => 'Selangor'])]);

        PlaceSearch::search('Kajang');
        $this->assertSame('Kajang', PlaceSearch::search('KAJANG')['places'][0]['name']);

        Http::assertSentCount(1);
    }

    public function test_fewer_than_three_letters_ask_nothing(): void
    {
        $this->photon([]);

        $this->assertSame(['places' => [], 'error' => false], PlaceSearch::search(' ka '));
        Http::assertNothingSent();
    }

    public function test_when_photon_cant_be_asked_it_says_so_and_asks_again_next_time(): void
    {
        Http::preventStrayRequests();
        Http::fake([config('map.places.source').'*' => Http::sequence()
            ->push('Service unavailable', 503)
            ->pushFailedConnection('Connection timed out')
            ->push(['features' => [$this->feature('Kajang', 101.79, 2.99, ['state' => 'Selangor'])]])]);

        $this->assertSame(['places' => [], 'error' => true], PlaceSearch::search('Kajang'));
        $this->assertSame(['places' => [], 'error' => true], PlaceSearch::search('Kajang'));
        $this->assertSame('Kajang', PlaceSearch::search('Kajang')['places'][0]['name']);
    }

    public function test_the_places_come_as_json_for_the_public_pages(): void
    {
        $this->photon([$this->feature('Jalan Ampang', 101.7344, 3.1604, ['district' => 'Kampung Datuk Keramat', 'state' => 'Kuala Lumpur'])]);

        $this->getJson('/public/places?q=jalan+ampang')->assertOk()
            ->assertExactJson(['places' => [['name' => 'Jalan Ampang', 'about' => 'Kampung Datuk Keramat, Kuala Lumpur', 'lat' => 3.1604, 'lng' => 101.7344]], 'error' => false]);

        // Anything but text is as if nothing was typed.
        $this->getJson('/public/places?q[]=x')->assertOk()->assertExactJson(['places' => [], 'error' => false]);
    }

    public function test_the_maps_search_asks_for_places_too(): void
    {
        $this->get('/public/map')->assertOk()
            ->assertSee('data-places="'.route('public.places').'" data-places-about="https://photon.komoot.io"', false)
            ->assertSee('placeholder="Search a place, police district or station"', false);
    }
}
