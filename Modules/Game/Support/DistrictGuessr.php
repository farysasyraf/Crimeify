<?php

namespace Modules\Game\Support;

use Illuminate\Database\QueryException;
use Modules\Game\Entities\GuessrRun;
use Modules\Map\Entities\PoliceDistrict;

// DistrictGuessr's districts and points: a game is config('game.districtguessr.rounds') police districts from the Map
// module's dbo.PoliceDistricts, at random and none twice, each to find on the map. A guess scores all the points within
// `perfect` km of the district's pin, and fewer the further off, by about a third every `scale` km after: some 600 at
// 50 km, 370 at 100 km, and next to none from 300 km.
class DistrictGuessr
{
    /**
     * The levels: Easy zooms the map into the district's state as it's asked (the state's ISO code, its region, comes
     * with it); Hard shows the whole of Malaysia. Each is ranked on its own on Game scores.
     */
    public const Easy = 'easy';

    public const Hard = 'hard';

    public const Levels = [self::Easy, self::Hard];

    /**
     * A game's districts, as many as its rounds (a solo game's, or the number given, for a live one): name, state, the
     * region its pin is in (for the hint, and its code, like MY-08, for Easy's zoom), latitude and longitude. None when
     * there aren't enough, as until "php artisan map:import-crime" has run.
     *
     * @return list<array{name: string, state: string, place: string, region: string, lat: float, lng: float}>|null
     */
    public function deal(?int $rounds = null): ?array
    {
        $rounds ??= config('game.districtguessr.rounds');

        try {
            $districts = PoliceDistrict::query()->inRandomOrder()->limit($rounds)->get();
        } catch (QueryException) {
            // dbo.PoliceDistricts isn't there until migrate runs.
            return null;
        }

        return $districts->count() < $rounds ? null : $districts->map(fn (PoliceDistrict $district) => [
            'name' => $district->Name,
            'state' => $district->State,
            'place' => $district->regionName(),
            'region' => $district->Region,
            'lat' => $district->Latitude,
            'lng' => $district->Longitude,
        ])->values()->all();
    }

    /**
     * Whether there are enough districts to play with.
     */
    public function ready(): bool
    {
        try {
            return PoliceDistrict::query()->count() >= config('game.districtguessr.rounds');
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * What the page shows of a game's district while it's being guessed: its name and the region its pin is in, and
     * that region's code, for Easy's zoom; not where.
     *
     * @return array{round: int, name: string, place: string, region: string|null}
     */
    public function asked(GuessrRun $run): array
    {
        $round = $run->round();
        $district = $run->Districts[$round - 1];

        // Games started before the levels have no region kept.
        return ['round' => $round, 'name' => $district['name'], 'place' => $district['place'], 'region' => $district['region'] ?? null];
    }

    /**
     * The kilometres between two places on the earth, along its surface (the haversine formula).
     */
    public static function km(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $rad = fn (float $degrees) => $degrees * M_PI / 180;
        $a = sin($rad($toLat - $fromLat) / 2) ** 2
            + cos($rad($fromLat)) * cos($rad($toLat)) * sin($rad($toLng - $fromLng) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * A guess's points, by how far off it was: none for no guess.
     */
    public static function points(?float $km): int
    {
        $config = config('game.districtguessr');

        return match (true) {
            $km === null => 0,
            $km <= $config['perfect'] => $config['points'],
            default => (int) round($config['points'] * exp(-($km - $config['perfect']) / $config['scale'])),
        };
    }
}
