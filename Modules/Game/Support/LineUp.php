<?php

namespace Modules\Game\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Modules\Game\Entities\LineUpRun;
use Modules\Map\Support\CrimeData;

// Line-Up's rounds and points: each round is config('game.line_up.suspects') police districts of one state, from the
// Map module's dbo.CrimeStats and dbo.PoliceDistricts, standing in a line-up in no order, to put in order of their
// count of one crime type in one year, most first. They're picked so each has at least config('game.line_up.gap')
// times the next one's count, from config('game.least') up, so the order can be told. Each suspect in its place scores
// config('game.line_up.right'), and one place off, config('game.line_up.near'). The rounds of a game are of different
// states and crimes where there are enough, never the same state twice in a row; what can be dealt is read once and kept
// for a few minutes (ReadsCrimeSets), as Skyline's is.
class LineUp
{
    use ReadsCrimeSets;

    /**
     * How the suspects are told apart: their silhouettes (line-up-suspects.svg), one of each in a round.
     */
    public const Looks = ['cap', 'hoodie', 'glasses', 'bun', 'collar', 'beanie'];

    /**
     * A game's rounds, as many as asked (a solo game's to start with): each a state, the codes of the regions its
     * districts are in (for its outline on the map), the crime type and year, and its suspects as they first stand,
     * left to right: name, latitude, longitude, count and look. None when there aren't enough, as until
     * "php artisan map:import-crime" has run.
     *
     * @return list<array{state: string, regions: list<string>, category: string, type: string, year: int, suspects: list<array{name: string, lat: float, lng: float, crimes: int, look: string}>}>|null
     */
    public function deal(?int $rounds = null): ?array
    {
        $rounds ??= config('game.line_up.rounds');

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
            return count($this->deck()['sets']) >= config('game.line_up.rounds');
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * What a page shows of a round while it's being ordered, in the language given: the state, its regions' codes,
     * the crime and its icon, the year, and the suspects as they first stand, each with its letter, name, look and
     * where it is, but not its count.
     *
     * @param  array<string, mixed>  $round
     * @return array<string, mixed>
     */
    public static function face(array $round, ?string $locale = null): array
    {
        $icons = config('game.icons');

        return [
            'state' => $round['state'],
            'regions' => $round['regions'],
            'type' => __(CrimeData::typeLabel($round['category'], $round['type']), [], $locale),
            'icon' => $icons[$round['type']] ?? $icons[$round['category']] ?? 'local_police',
            'year' => $round['year'],
            'suspects' => array_map(fn (array $suspect, int $index) => [
                'letter' => chr(ord('A') + $index),
                'name' => $suspect['name'],
                'look' => $suspect['look'],
                'lat' => $suspect['lat'],
                'lng' => $suspect['lng'],
            ], $round['suspects'], array_keys($round['suspects'])),
        ];
    }

    /**
     * What a solo game's page shows of the round being asked, with its number.
     *
     * @return array<string, mixed>
     */
    public function asked(LineUpRun $run): array
    {
        $round = $run->round();

        return ['round' => $round] + self::face($run->Dealt[$round - 1]);
    }

    /**
     * The round's right order: its suspects, by where they first stood, most crime first.
     *
     * @param  array<string, mixed>  $round
     * @return list<int>
     */
    public static function truth(array $round): array
    {
        $crimes = array_column($round['suspects'], 'crimes');
        arsort($crimes);

        return array_keys($crimes);
    }

    /**
     * How far each suspect of an order stands from its right place: by where they first stood, 0 in its place, 1 one
     * place off, and so on. None for no order.
     *
     * @param  list<int>|null  $order
     * @param  array<string, mixed>  $round
     * @return list<int>|null
     */
    public static function offs(?array $order, array $round): ?array
    {
        if ($order === null) {
            return null;
        }

        $places = array_flip(self::truth($round));
        $offs = array_fill(0, count($round['suspects']), 0);
        foreach ($order as $at => $suspect) {
            $offs[$suspect] = abs($at - $places[$suspect]);
        }

        return $offs;
    }

    /**
     * An order's points: config('game.line_up.right') for each suspect in its place, config('game.line_up.near') for
     * each one place off; none for no order.
     *
     * @param  list<int>|null  $order
     * @param  array<string, mixed>  $round
     */
    public static function points(?array $order, array $round): int
    {
        $config = config('game.line_up');

        return array_sum(array_map(
            fn (int $off) => match ($off) {
                0 => $config['right'],
                1 => $config['near'],
                default => 0,
            },
            self::offs($order, $round) ?? [],
        ));
    }

    /**
     * The most a round can score: every suspect in its place.
     */
    public static function most(): int
    {
        return config('game.line_up.suspects') * config('game.line_up.right');
    }

    /**
     * Whether an order is one: each suspect of the round once, by where they first stood.
     *
     * @param  array<mixed>  $order
     */
    public static function valid(array $order): bool
    {
        $suspects = config('game.line_up.suspects');
        $sorted = $order;
        sort($sorted);

        return array_is_list($order) && $sorted === range(0, $suspects - 1);
    }

    /**
     * A round from what can be dealt: its suspects, picked so their counts are far enough apart (chain()), standing in
     * no order, each with a look of its own.
     *
     * @param  array{state: string, category: string, type: string, year: int, counts: array<string, int>}  $set
     * @param  array<string, array{0: float, 1: float, 2: string}>  $pins
     * @return array<string, mixed>
     */
    private function round(array $set, array $pins): array
    {
        $picked = self::chain($set['counts'], true) ?? [];
        // In no order: not by count, and never already right.
        $standing = $picked;
        while (count($standing) > 1 && $standing === $picked) {
            shuffle($standing);
        }
        $looks = Arr::random(self::Looks, count($standing));
        shuffle($looks);

        return [
            'state' => $set['state'],
            // Every region its districts are in, as Sabah's take in Labuan and Kuala Lumpur's Putrajaya.
            'regions' => array_values(array_unique(array_column($pins, 2))),
            'category' => $set['category'],
            'type' => $set['type'],
            'year' => $set['year'],
            'suspects' => array_map(fn (string $name, string $look) => [
                'name' => $name, 'lat' => $pins[$name][0], 'lng' => $pins[$name][1], 'crimes' => $set['counts'][$name], 'look' => $look,
            ], $standing, $looks),
        ];
    }

    /**
     * Districts of a set, most crime first, as many as a round has, each with at least config('game.line_up.gap')
     * times the next one's count, counted from config('game.least') up; at random among the ways there are when
     * $random, otherwise the first way, from the most down. None when there's no way.
     *
     * @param  array<string, int>  $counts
     * @return list<string>|null
     */
    private static function chain(array $counts, bool $random = false): ?array
    {
        $config = config('game.line_up');
        $counted = array_filter($counts, fn (int $crimes) => $crimes >= config('game.least'));
        arsort($counted);

        // Each try takes each district in turn from the most down when it fits under the last, or skips it at random,
        // so the suspects aren't always the same; the last try skips none, which finds a way whenever there's one.
        foreach (array_merge($random ? array_fill(0, 24, 0.35) : [], [0]) as $skip) {
            $chain = [];
            $last = null;
            foreach ($counted as $name => $crimes) {
                if (($last === null || $crimes * $config['gap'] <= $last) && ($skip === 0 || mt_rand() / mt_getrandmax() >= $skip)) {
                    $chain[] = $name;
                    $last = $crimes;
                }
            }
            if (count($chain) >= $config['suspects']) {
                // Not always the busiest districts: a run of them from anywhere in the chain.
                $from = $random ? random_int(0, count($chain) - $config['suspects']) : 0;

                return array_slice($chain, $from, $config['suspects']);
            }
        }

        return null;
    }

    /**
     * What can be dealt (ReadsCrimeSets): each state's districts with pins, and every state, crime type and year with
     * a line-up's worth of districts far enough apart.
     *
     * @return array{pins: array<string, array<string, array{0: float, 1: float, 2: string}>>, sets: list<array{state: string, category: string, type: string, year: int, counts: array<string, int>}>}
     */
    private function deck(): array
    {
        $deck = $this->crimeSets('line-up', config('game.line_up.suspects'));

        return ['pins' => $deck['pins'], 'sets' => array_values(array_filter($deck['sets'], fn (array $set) => self::chain($set['counts']) !== null))];
    }
}
