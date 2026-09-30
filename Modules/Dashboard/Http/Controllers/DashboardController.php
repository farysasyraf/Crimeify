<?php

namespace Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\CrimeData;
use Modules\Map\Support\StateCrime;

// The page to start from after logging in: Malaysia's crime figures at a glance, from the Map module's copy of
// data.gov.my's crime by district file, loaded with "php artisan map:import-crime", and for the years it doesn't
// have yet, the police's crime index by state, loaded with "php artisan map:import-state-crime".
class DashboardController extends Controller
{
    /**
     * One year's crime figures, the latest unless ?year= asks for another: cards with totals and how they
     * compare with the year before, then charts of every year's totals, each state's and each crime type's.
     */
    public function index(Request $request): View
    {
        return $this->page($request, public: false);
    }

    /**
     * The same dashboard for everyone, without logging in, in the public pages' layout (/public/dashboard).
     */
    public function publicIndex(Request $request): View
    {
        return $this->page($request, public: true);
    }

    private function page(Request $request, bool $public): View
    {
        $years = $this->years();
        $page = [
            'public' => $public,
            // Where choosing a year goes: this page again.
            'pageUrl' => $public ? route('public.dashboard') : page_url('dashboard'),
        ];

        if ($years === []) {
            return view('dashboard::index', $page + ['years' => []]);
        }

        $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : end($years);
        $previousYear = in_array($year - 1, $years, true) ? $year - 1 : null;
        $national = $this->national();
        // A year data.gov.my has no police district figures for, with the police's crime index by state (StateCrime).
        $byState = in_array($year, StateCrime::stateOnlyYears(), true);

        return view('dashboard::index', $page + [
            'years' => $years,
            'year' => $year,
            'previousYear' => $previousYear,
            'byState' => $byState,
            'cards' => $this->cards($national, $years, $year, $previousYear),
            'charts' => [
                'year' => $year,
                'categories' => array_map(fn (string $label) => __($label), config('map.crime.categories')),
                'trend' => $this->trend($national, $years),
                'regions' => $byState ? $this->stateRegions($year) : $this->regions($year),
                'types' => $this->types($national[$year] ?? []),
            ],
            'credit' => config('map.crime.credit'),
            'about' => config('map.crime.about'),
            // The years by state, credited to the police's crime index, and in them, the regions counted in another's.
            'stateYears' => StateCrime::stateOnlyYears(),
            'stateSource' => config('map.by_state.source'),
            'countedIn' => $byState
                ? collect(StateCrime::countedUnder())->mapWithKeys(fn (string $under, string $region) => [config("map.states.{$region}.name") => config("map.states.{$under}.name")])->all()
                : [],
            // The map beside it: the public one on the public dashboard, the app's once it's on the Routes page.
            'mapUrl' => $public
                ? (Route::has('public.map') ? route('public.map') : null)
                : (Route::has('map') ? route('map') : null),
        ]);
    }

    /**
     * The years there are crime figures for, oldest first. None until "php artisan map:import-crime" has run.
     *
     * @return list<int>
     */
    private function years(): array
    {
        try {
            return CrimeStat::query()->distinct()->orderBy('Year')->pluck('Year')->map(fn ($year) => (int) $year)->all();
        } catch (QueryException) {
            // dbo.CrimeStats isn't there until migrate runs.
            return [];
        }
    }

    /**
     * The whole country's figures, from the data's "Malaysia" rows.
     *
     * @return array<int, array<string, array<string, int>>> [Year][Category][Type] => crimes
     */
    private function national(): array
    {
        $national = [];

        CrimeStat::query()
            ->where('State', 'Malaysia')
            ->where('District', 'All')
            ->get(['Category', 'Type', 'Year', 'Crimes'])
            ->each(function (CrimeStat $row) use (&$national) {
                $national[$row->Year][$row->Category][$row->Type] = $row->Crimes;
            });

        return $national;
    }

    /**
     * Crimes of the types given as category.type, added up: "assault.all" is every violent crime, from the
     * category's total, or its types added up when the data has no total.
     *
     * @param  array<string, array<string, int>>  $figures
     * @param  list<string>  $types
     */
    private function count(array $figures, array $types): int
    {
        $count = 0;

        foreach ($types as $key) {
            [$category, $type] = explode('.', $key);
            $inCategory = $figures[$category] ?? [];
            $count += $type === 'all' ? ($inCategory['all'] ?? array_sum($inCategory)) : ($inCategory[$type] ?? 0);
        }

        return $count;
    }

    /**
     * The cards of figures: each one's count for the year, for every year, and how it compares with the year before.
     *
     * @param  array<int, array<string, array<string, int>>>  $national
     * @param  list<int>  $years
     * @return list<array<string, mixed>>
     */
    private function cards(array $national, array $years, int $year, ?int $previousYear): array
    {
        return collect(config('dashboard.cards'))->map(function (array $card) use ($national, $years, $year, $previousYear) {
            $history = [];
            foreach ($years as $each) {
                $history[$each] = $this->count($national[$each] ?? [], $card['count']);
            }

            return [
                'label' => __($card['label']),
                'icon' => $card['icon'],
                'tone' => $card['tone'],
                'value' => $history[$year],
                'previous' => $previousYear === null ? null : $history[$previousYear],
                'change' => $previousYear === null ? null : $this->change($history[$year], $history[$previousYear]),
                'history' => $history,
            ];
        })->values()->all();
    }

    /**
     * How a count compares with the year before: up, down or the same, and by what percentage, rounded.
     * No percentage for a rise from none.
     *
     * @return array{direction: string, percent: ?int}
     */
    private function change(int $now, int $before): array
    {
        return [
            'direction' => match (true) {
                $now > $before => 'up',
                $now < $before => 'down',
                default => 'same',
            },
            'percent' => $before === 0 ? null : (int) round(abs($now - $before) / $before * 100),
        ];
    }

    /**
     * Each category's total for every year, for the crime over the years chart.
     *
     * @param  array<int, array<string, array<string, int>>>  $national
     * @param  list<int>  $years
     * @return array{years: list<int>, series: list<array<string, mixed>>}
     */
    private function trend(array $national, array $years): array
    {
        $series = [];

        foreach (config('map.crime.categories') as $category => $label) {
            $series[] = [
                'label' => __($label),
                'colour' => config("dashboard.colours.categories.{$category}"),
                'counts' => array_map(fn (int $year) => $this->count($national[$year] ?? [], ["{$category}.all"]), $years),
            ];
        }

        return ['years' => $years, 'series' => $series];
    }

    /**
     * Each region's totals for the year, most crime first, added up from its police districts as the Map page
     * does, so Labuan and Putrajaya count on their own rather than in Sabah and Kuala Lumpur.
     *
     * @return list<array<string, mixed>>
     */
    private function regions(int $year): array
    {
        $regionOf = PoliceDistrict::query()->get(['State', 'Name', 'Region'])
            ->mapWithKeys(fn (PoliceDistrict $district) => [$district->State.'|'.$district->Name => $district->Region]);

        // [State|District][Category][Type] => crimes
        $figures = [];
        CrimeStat::query()
            ->where('Year', $year)
            ->where('State', '!=', 'Malaysia')
            ->where('District', '!=', 'All')
            ->get(['State', 'District', 'Category', 'Type', 'Crimes'])
            ->each(function (CrimeStat $row) use (&$figures) {
                $figures[$row->State.'|'.$row->District][$row->Category][$row->Type] = $row->Crimes;
            });

        $totals = [];
        foreach ($figures as $place => $byCategory) {
            $region = $regionOf[$place] ?? null;

            if ($region === null) {
                continue;
            }

            foreach (array_keys(config('map.crime.categories')) as $category) {
                $totals[$region][$category] = ($totals[$region][$category] ?? 0) + $this->count($byCategory, ["{$category}.all"]);
            }
        }

        return $this->rankRegions($totals);
    }

    /**
     * Each region's totals for a year with figures by state only: its state's, so Labuan's are in Sabah's and
     * Putrajaya's in Kuala Lumpur's, as the police's crime index has them, and their names say so.
     *
     * @return list<array<string, mixed>>
     */
    private function stateRegions(int $year): array
    {
        // [State][Category][Type] => crimes
        $figures = [];
        CrimeStat::query()
            ->where('Year', $year)
            ->where('State', '!=', 'Malaysia')
            ->where('District', 'All')
            ->get(['State', 'Category', 'Type', 'Crimes'])
            ->each(function (CrimeStat $row) use (&$figures) {
                $figures[$row->State][$row->Category][$row->Type] = $row->Crimes;
            });

        $totals = [];
        foreach (StateCrime::regions() as $state => $code) {
            foreach (array_keys(config('map.crime.categories')) as $category) {
                if (isset($figures[$state])) {
                    $totals[$code][$category] = $this->count($figures[$state], ["{$category}.all"]);
                }
            }
        }

        return $this->rankRegions($totals, StateCrime::countedUnder());
    }

    /**
     * Regions' totals as the crime by state chart has them, most crime first, each named with the regions counted
     * in it, like "Sabah (with Labuan)".
     *
     * @param  array<string, array<string, int>>  $totals  [region code][category] => crimes
     * @param  array<string, string>  $countedUnder  region code => the region code it's counted in
     * @return list<array<string, mixed>>
     */
    private function rankRegions(array $totals, array $countedUnder = []): array
    {
        return collect($totals)
            ->map(function (array $byCategory, string $code) use ($countedUnder) {
                $with = collect($countedUnder)->filter(fn (string $under) => $under === $code)->keys()
                    ->map(fn (string $region) => config("map.states.{$region}.name"));

                return [
                    'code' => $code,
                    'name' => $with->isEmpty()
                        ? config("map.states.{$code}.name", $code)
                        : __(':region (with :others)', ['region' => config("map.states.{$code}.name", $code), 'others' => $with->implode(' '.__('and').' ')]),
                    'short' => config("dashboard.abbreviations.{$code}", $code),
                    'totals' => $byCategory,
                    'total' => array_sum($byCategory),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * The year's crime types, largest first: the few largest on their own and the rest added up, each with its
     * share of all crime. The types the year's figures have: data.gov.my's, or for a year by state, the police's
     * crime index's, which has robbery as one.
     *
     * @param  array<string, array<string, int>>  $figures
     * @return array{total: int, slices: list<array<string, mixed>>}
     */
    private function types(array $figures): array
    {
        $types = [];
        foreach (array_keys(config('map.crime.categories')) as $category) {
            foreach (array_diff_key($figures[$category] ?? [], ['all' => true]) as $type => $count) {
                $types[] = ['label' => __(CrimeData::typeLabel($category, $type)), 'count' => $count];
            }
        }

        $types = collect($types)->sortByDesc('count')->values();
        $shown = config('dashboard.types_shown');
        $slices = $types->take($shown)->all();

        if ($types->count() > $shown) {
            $slices[] = ['label' => __('All other types'), 'count' => $types->skip($shown)->sum('count')];
        }

        $total = $types->sum('count');
        $colours = config('dashboard.colours.slices');

        foreach ($slices as $index => &$slice) {
            $slice['share'] = $total === 0 ? 0 : round($slice['count'] / $total * 100, 1);
            $slice['colour'] = $colours[$index % count($colours)];
        }
        unset($slice);

        return ['total' => $total, 'slices' => $slices];
    }
}
