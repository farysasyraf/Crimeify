<?php

namespace Modules\Game\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\CrimeData;

// Case File's cases, clues and points: each case is a police district, from the Map module's dbo.PoliceDistricts and
// dbo.CrimeStats, to pick out from config('game.case_file.suspects') districts near it by the clues on the desk, which
// say of it:
//
// - rap: its crimes in the latest year, and the crime there was most of (of the types the games use);
// - trend: how much they went up or down from the year before, and since the first year;
// - rank: its place in its state by those crimes;
// - photo: its state, with a blur round about where it is, off its pin by up to half the blur's size;
// - tip: the first letter of its name.
//
// The rap sheet shows to start with. Each other clue opened takes its points off the case's (config('game.case_file
// .clues')). What can be dealt is read from the tables once and kept for a few minutes (config('game.deck')), as
// Higher or Lower's cards are.
class CaseFile
{
    /**
     * What can be dealt as read this request.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $deck = [];

    /**
     * A game's cases, as many as asked (a solo game's to start with), each of a different district, from different
     * states as far as there are enough: the district (name and state), its suspects (each a name and state, the
     * district among them at culprit), and what each clue says of it. None when there aren't enough districts or
     * figures, as until "php artisan map:import-crime" has run.
     *
     * @return list<array{name: string, state: string, culprit: int, suspects: list<array{name: string, state: string}>, clues: array<string, array<string, mixed>>}>|null
     */
    public function deal(?int $cases = null): ?array
    {
        $cases ??= config('game.case_file.cases');

        try {
            $deck = $this->deck();
        } catch (QueryException) {
            // dbo.CrimeStats isn't there until migrate runs.
            return null;
        }

        $names = array_keys($deck['districts']);
        if (count($names) < max($cases, config('game.case_file.suspects'))) {
            return null;
        }

        shuffle($names);
        $picked = [];
        $states = [];
        foreach ([true, false] as $picky) {
            foreach ($names as $name) {
                if (count($picked) === $cases) {
                    break 2;
                }

                $state = $deck['districts'][$name]['state'];
                if (in_array($name, $picked, true) || ($picky && isset($states[$state]))) {
                    continue;
                }

                $picked[] = $name;
                $states[$state] = true;
            }
        }

        return array_map(fn (string $name) => $this->case($name, $deck), $picked);
    }

    /**
     * Whether there are enough districts with figures to play with.
     */
    public function ready(): bool
    {
        try {
            return count($this->deck()['districts']) >= max(config('game.case_file.cases'), config('game.case_file.suspects'));
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * The clues, in the order a live game opens them, the rap sheet first.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        return array_keys(config('game.case_file.clues'));
    }

    /**
     * A case's points with the clues given open: all of them, less each clue's.
     *
     * @param  list<string>  $opened
     */
    public static function worth(array $opened): int
    {
        $costs = config('game.case_file.clues');

        return config('game.case_file.points') - array_sum(array_map(fn (string $clue) => $costs[$clue] ?? 0, array_unique($opened)));
    }

    /**
     * A case's points with the first clues open, as many as given (at least the rap sheet), as a live game opens them.
     */
    public static function worthAt(int $shown): int
    {
        return self::worth(array_slice(self::order(), 0, max(1, $shown)));
    }

    /**
     * What a page shows of a case's suspects: each one's name and state, in their order on the board.
     *
     * @param  array<string, mixed>  $case
     * @return list<array{name: string, state: string}>
     */
    public static function suspects(array $case): array
    {
        return $case['suspects'];
    }

    /**
     * What a clue says, in the language given: as dealt, and for the rap sheet, the crime's name and icon.
     *
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    public static function clue(array $case, string $clue, ?string $locale = null): array
    {
        $said = $case['clues'][$clue];

        if ($clue === 'rap') {
            $icons = config('game.icons');
            $said = ['year' => $said['year'], 'crimes' => $said['crimes'], 'count' => $said['count'],
                'type' => __(CrimeData::typeLabel($said['category'], $said['type']), [], $locale),
                'icon' => $icons[$said['type']] ?? $icons[$said['category']] ?? 'local_police'];
        }

        return $said;
    }

    /**
     * The clues given, each by its name, in the language given.
     *
     * @param  array<string, mixed>  $case
     * @param  list<string>  $clues
     * @return array<string, array<string, mixed>>
     */
    public static function clues(array $case, array $clues, ?string $locale = null): array
    {
        $said = [];
        foreach (self::order() as $clue) {
            if (in_array($clue, $clues, true)) {
                $said[$clue] = self::clue($case, $clue, $locale);
            }
        }

        return $said;
    }

    /**
     * A case of the district given: its suspects, the six (config('game.case_file.suspects')) nearest a district near
     * it, itself or one of its three nearest, so it isn't always the one in the middle of them, in a shuffled order;
     * and what each clue says of it.
     *
     * @param  array<string, mixed>  $deck
     * @return array<string, mixed>
     */
    private function case(string $name, array $deck): array
    {
        $districts = $deck['districts'];
        $count = config('game.case_file.suspects');
        $nearest = fn (string $from) => collect($districts)->except($from)
            ->sortBy(fn (array $other) => DistrictGuessr::km($districts[$from]['lat'], $districts[$from]['lng'], $other['lat'], $other['lng']))
            ->keys()->all();

        $anchor = Arr::random([$name, ...array_slice($nearest($name), 0, 3)]);
        $around = array_slice([$anchor, ...$nearest($anchor)], 0, $count);
        if (! in_array($name, $around, true)) {
            $around[$count - 1] = $name;
        }
        shuffle($around);

        $me = $districts[$name];
        $latest = $deck['latest'];
        $totals = $deck['totals'][$name] ?? [];
        $crimes = $totals[$latest] ?? 0;
        $change = fn (?int $before) => $before ? (int) round(($crimes - $before) / $before * 100) : null;

        // Its place in its state: one more than the districts with more crime.
        $inState = array_keys(array_filter($districts, fn (array $other) => $other['state'] === $me['state']));
        $more = count(array_filter($inState, fn (string $other) => ($deck['totals'][$other][$latest] ?? 0) > $crimes));

        // The blur: a share of the state's breadth across, round a point off the pin by up to half of it.
        $km = round(max(3, $deck['breadth'][$me['state']] * config('game.case_file.blur')), 1);
        $bearing = deg2rad(random_int(0, 359));
        $off = $km * random_int(0, 50) / 100;
        $top = $deck['tops'][$name] ?? null;

        return [
            'name' => $name,
            'state' => $me['state'],
            'culprit' => array_search($name, $around, true),
            'suspects' => array_map(fn (string $suspect) => ['name' => $suspect, 'state' => $districts[$suspect]['state']], $around),
            'clues' => [
                'rap' => ['year' => $latest, 'crimes' => $crimes, 'category' => $top[0] ?? 'property', 'type' => $top[1] ?? 'theft_other', 'count' => $top[2] ?? 0],
                'trend' => ['from' => $latest - 1, 'to' => $latest, 'change' => $change($totals[$latest - 1] ?? null), 'since' => $deck['first'], 'long' => $change($totals[$deck['first']] ?? null)],
                'rank' => ['rank' => $more + 1, 'of' => count($inState), 'year' => $latest],
                'photo' => [
                    'regions' => $deck['regions'][$me['state']],
                    'lat' => round($me['lat'] + $off * cos($bearing) / 111.32, 5),
                    'lng' => round($me['lng'] + $off * sin($bearing) / (111.32 * cos(deg2rad($me['lat']))), 5),
                    'km' => $km,
                ],
                'tip' => ['letter' => mb_strtoupper(mb_substr($name, 0, 1))],
            ],
        ];
    }

    /**
     * What can be dealt: each police district with a pin and figures in the latest year, as its state, region and
     * pin; each one's crimes a year (the categories' totals, or their types' added up where there are none); the crime
     * type it had most of in the latest year, of those the map lists but config('game.left_out'); each state's regions
     * and breadth across (its furthest pins apart, in km); and the first and latest years. Kept for
     * config('game.deck.keep') seconds, under a key for the settings that decide it.
     *
     * @return array{districts: array<string, array{state: string, region: string, lat: float, lng: float}>, totals: array<string, array<int, int>>, tops: array<string, array{0: string, 1: string, 2: int}>, regions: array<string, list<string>>, breadth: array<string, float>, first: int, latest: int}
     */
    private function deck(): array
    {
        $key = 'game.case-file.'.md5(json_encode([config('game.left_out'), config('map.crime.types')]));

        return $this->deck[$key] ??= $this->store()->remember($key, config('game.deck.keep'), function () {
            $pins = [];
            foreach (PoliceDistrict::query()->orderBy('Name')->get(['State', 'Name', 'Region', 'Latitude', 'Longitude']) as $district) {
                $pins[$district->Name] = ['state' => $district->State, 'region' => $district->Region, 'lat' => (float) $district->Latitude, 'lng' => (float) $district->Longitude];
            }

            $types = config('map.crime.types');
            $alls = [];
            $sums = [];
            $byType = [];
            foreach (CrimeStat::query()->where('State', '!=', 'Malaysia')->where('District', '!=', 'All')->get(['District', 'Category', 'Type', 'Year', 'Crimes']) as $row) {
                if (! isset($pins[$row->District])) {
                    continue;
                }

                [$district, $year, $crimes] = [$row->District, (int) $row->Year, (int) $row->Crimes];
                if ($row->Type === 'all') {
                    $alls[$district][$year] = ($alls[$district][$year] ?? 0) + $crimes;

                    continue;
                }

                $sums[$district][$year] = ($sums[$district][$year] ?? 0) + $crimes;
                if (isset($types[$row->Category][$row->Type]) && ! in_array($row->Type, config('game.left_out'), true)) {
                    $byType[$district][$year][] = [$row->Category, $row->Type, $crimes];
                }
            }

            $totals = [];
            foreach (array_keys($pins) as $district) {
                $totals[$district] = ($alls[$district] ?? []) + ($sums[$district] ?? []);
            }
            $years = array_merge([], ...array_map('array_keys', array_values($totals)));
            if ($years === []) {
                return ['districts' => [], 'totals' => [], 'tops' => [], 'regions' => [], 'breadth' => [], 'first' => 0, 'latest' => 0];
            }
            [$first, $latest] = [min($years), max($years)];

            // Only districts with figures in the latest year can be cases or suspects.
            $districts = array_filter($pins, fn (string $district) => isset($totals[$district][$latest]), ARRAY_FILTER_USE_KEY);

            $tops = [];
            foreach (array_keys($districts) as $district) {
                $rows = $byType[$district][$latest] ?? [];
                usort($rows, fn (array $a, array $b) => $b[2] <=> $a[2] ?: strcmp($a[1], $b[1]));
                if ($rows !== []) {
                    $tops[$district] = $rows[0];
                }
            }

            $regions = [];
            $breadth = [];
            foreach ($districts as $district) {
                $regions[$district['state']][$district['region']] = true;
            }
            foreach (array_keys($regions) as $state) {
                $inState = array_values(array_filter($districts, fn (array $district) => $district['state'] === $state));
                $furthest = 0;
                foreach ($inState as $i => $a) {
                    foreach (array_slice($inState, $i + 1) as $b) {
                        $furthest = max($furthest, DistrictGuessr::km($a['lat'], $a['lng'], $b['lat'], $b['lng']));
                    }
                }
                $breadth[$state] = round($furthest, 1);
                $regions[$state] = array_keys($regions[$state]);
            }

            return ['districts' => $districts, 'totals' => $totals, 'tops' => $tops, 'regions' => $regions, 'breadth' => $breadth, 'first' => $first, 'latest' => $latest];
        });
    }

    private function store(): Repository
    {
        return Cache::store(config('game.deck.store'));
    }
}
