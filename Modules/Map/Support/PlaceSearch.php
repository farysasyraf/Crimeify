<?php

namespace Modules\Map\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Places and addresses in Malaysia by name, like "Taman Melawati", "Jalan Ampang" or "47500", for the map's search and
// Check an area: each one's name, where it is in words (its area, district and state), and its point, to find the
// police districts nearest it. From Photon (config('map.places')), komoot's search of OpenStreetMap's places, which,
// unlike OpenStreetMap's own Nominatim, may be asked as someone types. It's asked by this server rather than the
// visitor's browser, so visitors' addresses on the internet aren't passed on, and each search is kept a week, so the
// same search isn't asked twice.
final class PlaceSearch
{
    /**
     * Fewer letters than this find too much to be any use.
     */
    public const MinLength = 3;

    /**
     * At most this many places.
     */
    private const Limit = 6;

    /**
     * Malaysia with a margin, west, south, east, north, so Photon looks there first. It takes in Singapore and a
     * little of Thailand and Indonesia, whose places are left out after.
     */
    private const Bounds = '99.6,0.8,119.3,7.4';

    /**
     * What isn't a place to live or stay near: railway and bus lines, stations and stops, and car park roads.
     */
    private const Excluded = ['railway', 'route', 'public_transport', 'highway:bus_stop', 'highway:platform', 'highway:service'];

    /**
     * The places matching what was typed, best first, and whether Photon couldn't be asked. Nothing for fewer than
     * three letters. A failed search isn't kept, so it's asked again next time.
     *
     * @return array{places: list<array{name: string, about: string, lat: float, lng: float}>, error: bool}
     */
    public static function search(string $query): array
    {
        $query = Str::squish(mb_substr($query, 0, 100));

        if (mb_strlen($query) < self::MinLength) {
            return ['places' => [], 'error' => false];
        }

        $key = 'map.places.'.sha1(Str::lower($query));
        $kept = Cache::get($key);

        if (is_array($kept)) {
            return ['places' => $kept, 'error' => false];
        }

        // Photon takes osm_tag once for each kind it leaves out, so the address is put together by hand.
        $url = config('map.places.source').'?'.http_build_query(['q' => $query, 'limit' => self::Limit * 2, 'bbox' => self::Bounds])
            .implode('', array_map(fn (string $tag) => '&osm_tag='.rawurlencode('!'.$tag), self::Excluded));

        try {
            $response = Http::timeout(6)->withUserAgent(config('app.name').' ('.config('app.url').')')->acceptJson()->get($url);
        } catch (ConnectionException) {
            return ['places' => [], 'error' => true];
        }

        if ($response->failed() || ! is_array($response->json('features'))) {
            return ['places' => [], 'error' => true];
        }

        $places = self::read($response->json('features'));
        Cache::put($key, $places, now()->addWeek());

        return ['places' => $places, 'error' => false];
    }

    /**
     * Photon's places in Malaysia, each with its name, where it is in words, and its point, once each: the same name in
     * the same place is one place, like a road Photon gives in two parts.
     *
     * @param  array<int, mixed>  $features
     * @return list<array{name: string, about: string, lat: float, lng: float}>
     */
    private static function read(array $features): array
    {
        $places = [];

        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            [$lng, $lat] = array_pad($feature['geometry']['coordinates'] ?? [], 2, null);

            if (($properties['countrycode'] ?? null) !== 'MY' || ! is_numeric($lat) || ! is_numeric($lng) || blank($properties['name'] ?? null)) {
                continue;
            }

            $name = (string) $properties['name'];
            // Where it is, from the nearest outwards, each once and not its own name.
            $about = collect([$properties['district'] ?? null, $properties['city'] ?? null, $properties['county'] ?? null, $properties['state'] ?? null])
                ->filter(fn ($part) => filled($part) && $part !== $name)
                ->unique()
                ->implode(', ');

            $places[$name.'|'.$about] ??= ['name' => $name, 'about' => $about, 'lat' => (float) $lat, 'lng' => (float) $lng];
        }

        return array_slice(array_values($places), 0, self::Limit);
    }
}
