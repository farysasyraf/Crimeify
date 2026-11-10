<?php

namespace Modules\Game\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Modules\Game\Entities\SkylineRun;
use Modules\Map\Support\CrimeData;

// Skyline's rounds and points: each round is one state's police districts, from the Map module's dbo.CrimeStats and
// dbo.PoliceDistricts, as 3D towers on its outline, each as tall as its count of one crime type in one year, one of
// them greyed out to raise to the height thought right. A state can be dealt for a crime and a year when
// config('game.skyline.districts') of its districts have counts from config('game.least') up; the tower to guess is
// one of those, but not the tallest, whose count shows, to judge by. The rounds of a game are of different states and
// crimes where there are enough. As with Higher or Lower's cards (Cards), what can be dealt is read from the tables once
// and kept for a few minutes (config('game.deck'), ReadsCrimeSets), rather than read each time a game starts.
class Skyline
{
    use ReadsCrimeSets;

    /**
     * A game's rounds, as many as asked (a solo game's to start with): each a state, the codes of the regions its
     * districts are in (for its outline), the crime type and year, the district to guess and its count, and every district's tower: name,
     * latitude, longitude and count. None when there aren't enough, as until "php artisan map:import-crime" has run.
     *
     * @return list<array{state: string, regions: list<string>, category: string, type: string, year: int, target: string, crimes: int, towers: list<array{name: string, lat: float, lng: float, crimes: int}>}>|null
     */
    public function deal(?int $rounds = null): ?array
    {
        $rounds ??= config('game.skyline.rounds');

        try {
            $deck = $this->deck();
        } catch (QueryException) {
            // dbo.CrimeStats isn't there until migrate runs.
            return null;
        }

        // Each state and crime once, and no state twice in a row, as far as there are enough; then any not dealt yet.
        $sets = collect($deck['sets'])->shuffle()->values()->all();
        $dealt = [];
        $taken = [];
        $used = [];
        foreach ([true, false] as $picky) {
            foreach ($sets as $index => $set) {
                if (count($dealt) === $rounds) {
                    break 2;
                }

                $fresh = ! isset($used["{$set['state']}/{$set['type']}"]) && ($dealt === [] || $dealt[count($dealt) - 1]['state'] !== $set['state']);
                if (isset($taken[$index]) || ($picky && ! $fresh)) {
                    continue;
                }

                $dealt[] = $set;
                $taken[$index] = true;
                $used["{$set['state']}/{$set['type']}"] = true;
            }
        }

        if (count($dealt) < $rounds) {
            return null;
        }

        return array_map(fn (array $set) => $this->round($set, $deck['pins'][$set['state']]), $dealt);
    }

    /**
     * Whether there are enough rounds to play with.
     */
    public function ready(): bool
    {
        try {
            return count($this->deck()['sets']) >= config('game.skyline.rounds');
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * What a page shows of a round while it's being guessed, in the language given: the state, its regions' codes, the
     * crime and its icon, the year, the district to guess, every tower but its count, the tallest by name and count, and
     * how high the guess can go.
     *
     * @param  array<string, mixed>  $round
     * @return array<string, mixed>
     */
    public static function face(array $round, ?string $locale = null): array
    {
        $tallest = collect($round['towers'])->sortByDesc('crimes')->first();
        $icons = config('game.icons');

        return [
            'state' => $round['state'],
            'regions' => $round['regions'],
            'type' => __(CrimeData::typeLabel($round['category'], $round['type']), [], $locale),
            'icon' => $icons[$round['type']] ?? $icons[$round['category']] ?? 'local_police',
            'year' => $round['year'],
            'target' => $round['target'],
            'towers' => array_map(fn (array $tower) => [
                'name' => $tower['name'],
                'lat' => $tower['lat'],
                'lng' => $tower['lng'],
                'crimes' => $tower['name'] === $round['target'] ? null : $tower['crimes'],
            ], $round['towers']),
            'tallest' => ['name' => $tallest['name'], 'crimes' => $tallest['crimes']],
            'most' => self::most($round),
        ];
    }

    /**
     * The highest a guess can go: config('game.skyline.headroom') times the tallest, so the tower to guess can be raised
     * above any of them.
     *
     * @param  array<string, mixed>  $round
     */
    public static function most(array $round): int
    {
        return (int) ceil(max(array_column($round['towers'], 'crimes')) * config('game.skyline.headroom'));
    }

    /**
     * What a solo game's page shows of the round being asked, with its number.
     *
     * @return array<string, mixed>
     */
    public function asked(SkylineRun $run): array
    {
        $round = $run->round();

        return ['round' => $round] + self::face($run->Dealt[$round - 1]);
    }

    /**
     * A guess's points, by how far off it was, as a share of the count: all of them within
     * config('game.skyline.perfect'), then fewer the further off; none for no guess.
     */
    public static function points(?int $guess, int $crimes): int
    {
        $config = config('game.skyline');

        if ($guess === null) {
            return 0;
        }

        $off = abs($guess - $crimes) / max($crimes, 1);

        return $off <= $config['perfect'] ? $config['points'] : (int) round($config['points'] * exp(-$config['falloff'] * $off));
    }

    /**
     * A round from what can be dealt: the tower to guess, at random from those counted, but not the tallest.
     *
     * @param  array{state: string, category: string, type: string, year: int, counts: array<string, int>}  $set
     * @param  array<string, array{0: float, 1: float, 2: string}>  $pins
     * @return array<string, mixed>
     */
    private function round(array $set, array $pins): array
    {
        $tallest = max($set['counts']);
        $choices = array_keys(array_filter($set['counts'], fn (int $crimes) => $crimes >= config('game.least') && $crimes < $tallest));
        $target = Arr::random($choices);

        return [
            'state' => $set['state'],
            // Every region its districts are in, as Sabah's take in Labuan and Kuala Lumpur's Putrajaya.
            'regions' => array_values(array_unique(array_column($pins, 2))),
            'category' => $set['category'],
            'type' => $set['type'],
            'year' => $set['year'],
            'target' => $target,
            'crimes' => $set['counts'][$target],
            // With more than one array, array_map gives a list.
            'towers' => array_map(fn (string $name, array $pin) => [
                'name' => $name, 'lat' => $pin[0], 'lng' => $pin[1], 'crimes' => $set['counts'][$name] ?? 0,
            ], array_keys($pins), $pins),
        ];
    }

    /**
     * What can be dealt (ReadsCrimeSets): each state's districts with pins, and every state, crime type and year with
     * config('game.skyline.districts') towers, a tower to guess among them that isn't the tallest.
     *
     * @return array{pins: array<string, array<string, array{0: float, 1: float, 2: string}>>, sets: list<array{state: string, category: string, type: string, year: int, counts: array<string, int>}>}
     */
    private function deck(): array
    {
        return $this->crimeSets('skyline', config('game.skyline.districts'));
    }
}
