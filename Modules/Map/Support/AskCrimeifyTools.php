<?php

namespace Modules\Map\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;

// What Ask Crimeify (AskCrimeify) can look up, as tools Claude calls: places by name, a police district's figures, a
// state's or Malaysia's, and the police districts ranked by a crime. Each answers from the app's own figures, counted
// as the map and the area profiles count them, so an answer agrees with the pages it links to. Nothing here changes
// anything. A tool given something it can't use throws InvalidArgumentException, whose message goes back to Claude to
// try again.
final class AskCrimeifyTools
{
    /**
     * Places found by name at most, of each kind.
     */
    private const Found = 8;

    /**
     * Police districts ranked at most.
     */
    private const MostRanked = 15;

    /**
     * What every district figure needs said with it.
     */
    private const NotPerPerson = "Crimes reported to the police, for the whole police district. Not per person: police districts' populations aren't published, so a district where more people live or work has more crime for that alone.";

    /**
     * The tools' definitions for the Messages API, always in this order, so the prompt cache holds. Strict, so each
     * call's input is as its schema says.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $crimes = ['all', ...array_keys(config('map.crime.categories')), ...collect(config('map.crime.types'))->flatMap(fn (array $types) => array_keys($types))->all()];
        $named = collect(config('map.crime.types'))->flatMap(fn (array $types) => $types)->map(fn (string $label, string $type) => "{$type} ({$label})")->implode(', ');

        return [
            [
                'name' => 'find_places',
                'description' => "Find Malaysia's states and police districts by name, as Crimeify has them, to get the region code and district name the other tools take. Matches part of a name in any letter case. When nothing matches, it looks the name up as a place, like a neighbourhood, road or postcode, and gives the police districts whose pins are nearest it.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'A state, police district or place, like "Johor", "Kajang" or "Taman Melawati".'],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'district_figures',
                'description' => "A police district's figures: violent, property and all crime each year, the change from the year before and since the first year beside its state's and Malaysia's, its latest year's crimes by type beside the state's mix, its profile address to link to, and where known, household income, poverty and unemployment in the administrative district its pin is in.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => ['type' => 'string', 'description' => 'The region code from find_places, like "MY-10".'],
                        'district' => ['type' => 'string', 'description' => 'The police district\'s name from find_places, like "Kajang".'],
                    ],
                    'required' => ['region', 'district'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'state_figures',
                'description' => "A state's or federal territory's figures, or Malaysia's: violent, property and all crime each year, including years published by state only, and crime per 100,000 people with where it ranks among the states by that.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => ['type' => 'string', 'description' => 'The region code from find_places, like "MY-01", or "Malaysia".'],
                    ],
                    'required' => ['region'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'rank_districts',
                'description' => "Police districts ranked by a crime in a year, across Malaysia or within a state: the most or the fewest crimes, or with from_year, the biggest rise or fall in percent between the two years. By counts, not per person. Crime types: {$named}; or assault (violent crime), property (property crime) or all.",
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => ['type' => ['string', 'null'], 'description' => 'A region code to rank within, like "MY-01", or null for all of Malaysia.'],
                        'crime' => ['type' => 'string', 'enum' => $crimes, 'description' => 'What to count.'],
                        'year' => ['type' => ['integer', 'null'], 'description' => 'The year, or null for the latest with police district figures.'],
                        'from_year' => ['type' => ['integer', 'null'], 'description' => 'For a rise or fall, the earlier year to compare with; otherwise null.'],
                        'order' => ['type' => 'string', 'enum' => ['most', 'fewest', 'biggest_rise', 'biggest_fall']],
                        'limit' => ['type' => ['integer', 'null'], 'description' => 'How many to give, up to '.self::MostRanked.'; null for 5.'],
                    ],
                    'required' => ['region', 'crime', 'year', 'from_year', 'order', 'limit'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
        ];
    }

    /**
     * A tool's answer, to send back to Claude as JSON.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $name, array $input): array
    {
        return match ($name) {
            'find_places' => $this->findPlaces((string) ($input['query'] ?? '')),
            'district_figures' => $this->districtFigures((string) ($input['region'] ?? ''), (string) ($input['district'] ?? '')),
            'state_figures' => $this->stateFigures((string) ($input['region'] ?? '')),
            'rank_districts' => $this->rankDistricts($input),
            default => throw new InvalidArgumentException("There's no tool called {$name}."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function findPlaces(string $query): array
    {
        $query = Str::squish($query);
        $like = fn (string $name) => $query !== '' && Str::contains(Str::ascii($name), Str::ascii($query), ignoreCase: true);

        $states = collect(config('map.states'))
            ->filter(fn (array $state) => $like($state['name']))
            ->map(fn (array $state, string $code) => ['region' => $code, 'name' => $state['name']])
            ->values()->take(self::Found)->all();

        $districts = PoliceDistrict::query()->orderBy('Name')->get()
            ->filter(fn (PoliceDistrict $district) => $like($district->Name))
            ->map(fn (PoliceDistrict $district) => $this->place($district))
            ->values()->take(self::Found)->all();

        if ($states !== [] || $districts !== []) {
            return ['states' => $states, 'police_districts' => $districts];
        }

        // Not a state or police district: a place, and the police districts nearest it, as "Near you" finds them.
        $found = PlaceSearch::search($query);
        $pins = PoliceDistrict::query()->get();

        return [
            'states' => [],
            'police_districts' => [],
            'places' => array_map(fn (array $place) => [
                'name' => $place['name'],
                'where' => $place['about'],
                'nearest_police_districts' => $pins
                    ->map(fn (PoliceDistrict $district) => $this->place($district) + ['km' => round(self::km($place['lat'], $place['lng'], $district->Latitude, $district->Longitude), 1)])
                    ->sortBy('km')->take(3)->values()->all(),
            ], array_slice($found['places'], 0, 3)),
            'note' => $found['error']
                ? "Places can't be looked up just now."
                : "Nearest in a straight line to each district's pin, mostly its police headquarters; near a district's edge, the nearest may be a neighbouring district.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function districtFigures(string $region, string $name): array
    {
        $district = PoliceDistrict::query()->where('Region', $region)->get()->first(fn (PoliceDistrict $district) => strcasecmp($district->Name, trim($name)) === 0)
            ?? throw new InvalidArgumentException("There's no police district called {$name} in {$region}. Use find_places for its name and region.");
        $profile = AreaProfile::of($district);

        if (! $profile->hasFigures()) {
            return $this->place($district) + ['years' => [], 'note' => "There are no crime figures for {$district->Name} yet."];
        }

        $long = $profile->longChange();
        $living = AreaLiving::of($district);

        return $this->place($district) + [
            'years' => array_map(fn (array $year) => ['year' => $year['year'], 'violent' => $year['assault'], 'property' => $year['property'], 'all' => $year['total']], $profile->yearly()),
            'change_from_year_before_percent' => self::rounded($profile->latestChange()),
            'change_since_first_year_percent' => [
                'from' => $profile->firstYear(),
                'to' => $profile->latestYear(),
                'here' => self::rounded($long['here']),
                $profile->area => self::rounded($long['area']),
                'Malaysia' => self::rounded($long['national']),
            ],
            'crime_types_latest_year' => array_map(fn (array $type) => [
                'type' => $type['label'],
                'crimes' => $type['count'],
                'share_percent' => $type['share'],
                "share_across_{$profile->area}_percent" => $type['areaShare'],
            ], $profile->mix()),
            'living_here' => $living === null ? null : [
                'administrative_district' => $living->name(),
                'also_takes_in' => $living->sharedWith,
            ] + collect(AreaLiving::Compared)->mapWithKeys(fn (string $measure) => [$measure => [
                'year' => $living->year($measure),
                'here' => $living->value($measure),
                'state' => $living->stateValue($measure),
                'malaysia' => $living->nationalValue($measure),
            ]])->all(),
            'note' => self::NotPerPerson.' Income and spending are ringgit a month; poverty and unemployment percent.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFigures(string $region): array
    {
        $national = strcasecmp($region, 'Malaysia') === 0;
        $place = $national ? 'Malaysia' : strtoupper(trim($region));

        if (! $national && ! isset(config('map.states')[$place])) {
            throw new InvalidArgumentException("There's no region {$region}. Use find_places for a state's region code, or \"Malaysia\".");
        }

        $stateYears = StateCrime::stateOnlyYears();
        $crimes = CrimeRates::crimes($stateYears);
        $rates = CrimeRates::all()['years'];
        $years = [];

        foreach ($crimes as $year => $places) {
            if (! isset($places[$place])) {
                continue;
            }

            $rate = $national ? ($rates[$year]['national'] ?? null) : ($rates[$year]['regions'][$place] ?? null);
            $ranked = collect($rates[$year]['regions'] ?? [])->sortByDesc(fn (array $region) => $region['rates']['all'])->keys()->values();

            $years[] = [
                'year' => $year,
                'violent' => $places[$place]['assault'] ?? 0,
                'property' => $places[$place]['property'] ?? 0,
                'all' => array_sum($places[$place]),
                'people' => $rate['people'] ?? null,
                'all_per_100000_people' => $rate === null ? null : round($rate['rates']['all'], 1),
                'violent_per_100000_people' => $rate === null ? null : round($rate['rates']['assault'], 1),
                'property_per_100000_people' => $rate === null ? null : round($rate['rates']['property'], 1),
                'rank_by_crime_per_person' => $national || $rate === null ? null : ['place' => $ranked->search($place) + 1, 'of' => $ranked->count(), 'first_is' => 'the most crime per person'],
            ];
        }

        return [
            'place' => $national ? 'Malaysia' : config("map.states.{$place}.name"),
            'region' => $national ? 'Malaysia' : $place,
            'years' => $years,
            'years_by_state_only' => $stateYears,
            'note' => 'Crimes reported to the police. In years by state only, Labuan\'s are counted in Sabah\'s and Putrajaya\'s in Kuala Lumpur\'s.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function rankDistricts(array $input): array
    {
        $years = StateCrime::districtYears();
        $year = $input['year'] ?? max($years ?: [0]);
        $from = $input['from_year'] ?? null;
        $order = (string) ($input['order'] ?? 'most');
        $crime = (string) ($input['crime'] ?? 'all');
        $region = $input['region'] ?? null;
        $limit = max(1, min(self::MostRanked, (int) ($input['limit'] ?? 5)));

        foreach (array_filter([$year, $from]) as $asked) {
            if (! in_array($asked, $years, true)) {
                throw new InvalidArgumentException('There are police district figures for '.implode(', ', $years)." only, not {$asked}.");
            }
        }
        if (in_array($order, ['biggest_rise', 'biggest_fall'], true) && ($from === null || $from >= $year)) {
            throw new InvalidArgumentException('A rise or fall needs from_year, a year before year.');
        }
        if ($region !== null && ! isset(config('map.states')[$region])) {
            throw new InvalidArgumentException("There's no region {$region}. Use find_places for a state's region code, or null for all of Malaysia.");
        }

        // The category and crime type counted: one type, one category's, or both categories'.
        $categoryOf = collect(config('map.crime.types'))->flatMap(fn (array $types, string $category) => array_fill_keys(array_keys($types), $category))->all();
        [$category, $type] = match (true) {
            isset($categoryOf[$crime]) => [$categoryOf[$crime], $crime],
            isset(config('map.crime.categories')[$crime]) => [$crime, null],
            default => [null, null],
        };

        // [State|District][Year][Category][Type] => crimes.
        $counts = [];
        CrimeStat::query()
            ->whereIn('Year', array_filter([$year, $from]))
            ->where('State', '<>', 'Malaysia')->where('District', '<>', 'All')
            ->when($category, fn ($query) => $query->where('Category', $category))
            ->get(['State', 'District', 'Category', 'Type', 'Year', 'Crimes'])
            ->each(function (CrimeStat $row) use (&$counts) {
                $counts[$row->State.'|'.$row->District][$row->Year][$row->Category][$row->Type] = $row->Crimes;
            });

        // A category's crimes: its "all" row, or its types added up, as the map counts them.
        $count = function (array $byCategory) use ($type): int {
            $total = 0;
            foreach ($byCategory as $category => $byType) {
                $total += $type !== null ? ($byType[$type] ?? 0) : ($byType['all'] ?? array_sum(array_diff_key($byType, ['all' => 0])));
            }

            return $total;
        };

        $districts = PoliceDistrict::query()->when($region, fn ($query) => $query->where('Region', $region))->get()
            ->map(function (PoliceDistrict $district) use ($counts, $count, $year, $from) {
                $byYear = $counts[$district->State.'|'.$district->Name] ?? [];
                $now = isset($byYear[$year]) ? $count($byYear[$year]) : null;
                $before = $from !== null && isset($byYear[$from]) ? $count($byYear[$from]) : null;

                return $this->place($district) + ['crimes' => $now] + ($from === null ? [] : [
                    'crimes_in_from_year' => $before,
                    'change_percent' => self::rounded(AreaProfile::change($before, $now)),
                ]);
            })
            ->filter(fn (array $district) => $district['crimes'] !== null);

        $ranked = match ($order) {
            'fewest' => $districts->sortBy([['crimes', 'asc'], ['district', 'asc']]),
            'biggest_rise' => $districts->whereNotNull('change_percent')->sortBy([['change_percent', 'desc'], ['district', 'asc']]),
            'biggest_fall' => $districts->whereNotNull('change_percent')->sortBy([['change_percent', 'asc'], ['district', 'asc']]),
            default => $districts->sortBy([['crimes', 'desc'], ['district', 'asc']]),
        };

        return [
            'crime' => $crime,
            'year' => $year,
            'from_year' => $from,
            'within' => $region === null ? 'Malaysia' : config("map.states.{$region}.name"),
            'districts_compared' => $ranked->count(),
            'ranked' => $ranked->take($limit)->values()->map(fn (array $district, int $at) => ['rank' => $at + 1] + $district)->all(),
            'note' => self::NotPerPerson,
        ];
    }

    /**
     * A police district as the tools give it: its name, state, region code and profile's address.
     *
     * @return array{district: string, state: string, region: string, profile: string}
     */
    private function place(PoliceDistrict $district): array
    {
        return [
            'district' => $district->Name,
            'state' => $district->regionName(),
            'region' => $district->Region,
            'profile' => route('public.areas.show', $district->path()),
        ];
    }

    private static function rounded(?float $change): ?float
    {
        return $change === null ? null : round($change, 1);
    }

    /**
     * The distance between two points on the earth, in kilometres.
     */
    private static function km(float $lat, float $lng, float $toLat, float $toLng): float
    {
        $a = sin(deg2rad($toLat - $lat) / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($toLat)) * sin(deg2rad($toLng - $lng) / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
