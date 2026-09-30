<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Support\StateCrime;

// The Map module's pages.
class MapController extends Controller
{
    /**
     * Malaysia's states and federal territories on an interactive map, with a list of them beside it, and a pin
     * for each police district with its crime figures for a chosen year. Under it, the police stations of a chosen
     * state, with the chosen one's address and phone number.
     */
    public function index(): View
    {
        return $this->page(public: false);
    }

    /**
     * The same map for everyone, without logging in, in the public pages' layout (/public/map).
     */
    public function publicIndex(): View
    {
        return $this->page(public: true);
    }

    private function page(bool $public): View
    {
        $regions = collect(config('map.states'))
            ->map(fn (array $region, string $code) => $region + ['code' => $code])
            ->sortBy('name')
            ->values();

        return view('map::index', [
            'public' => $public,
            'states' => $regions->where('territory', false)->values(),
            'territories' => $regions->where('territory', true)->values(),
            'boundaries' => versioned_asset(config('map.boundaries')),
            'years' => $this->years(),
            'stations' => $this->stations(),
            // The pins' figures come from MapController@crime: in the app, as "map/crime" added on the Routes page;
            // on the public map, from its own address in Routes/web.php.
            'crimeUrl' => $public ? route('public.map.crime') : (Route::has('map/crime') ? route('map/crime') : null),
        ]);
    }

    /**
     * One year's crime figures for the map, as JSON: each police district's pin with its totals and crime types,
     * each region's and the country's totals, and the year before's totals to compare with. Takes ?year=, and
     * gives the latest year for a missing or unknown one.
     */
    public function crime(Request $request): JsonResponse
    {
        $years = $this->years();
        $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : (end($years) ?: null);
        $previousYear = in_array($year - 1, $years, true) ? $year - 1 : null;

        // [State|District][Year][Category][Type] => number of crimes.
        $figures = [];
        CrimeStat::query()
            ->whereIn('Year', array_filter([$year, $previousYear]))
            ->get(['State', 'District', 'Category', 'Type', 'Year', 'Crimes'])
            ->each(function (CrimeStat $row) use (&$figures) {
                $figures[$row->State.'|'.$row->District][$row->Year][$row->Category][$row->Type] = $row->Crimes;
            });

        $districts = [];

        foreach (PoliceDistrict::orderBy('Name')->get() as $district) {
            $place = $district->State.'|'.$district->Name;

            if (! isset($figures[$place][$year])) {
                continue;
            }

            $districts[] = [
                'name' => $district->Name,
                'region' => $district->Region,
                'lat' => $district->Latitude,
                'lng' => $district->Longitude,
                'totals' => $this->totals($figures[$place][$year]),
                // None for a district without figures the year before, rather than a rise from nothing.
                'previous' => $previousYear !== null && isset($figures[$place][$previousYear])
                    ? $this->totals($figures[$place][$previousYear])
                    : null,
                'types' => $this->types($figures[$place][$year]),
            ];
        }

        // A region's totals are its districts', so Labuan and Putrajaya count on their own rather than inside
        // Sabah's and Kuala Lumpur's, as the data files them. A year with figures by state only (StateCrime) has no
        // districts: each region's totals are its state's, compared with the state's the year before, and Labuan's and
        // Putrajaya's are in Sabah's and Kuala Lumpur's.
        $byState = in_array($year, StateCrime::stateOnlyYears(), true);
        $regions = $byState
            ? $this->stateRegions($figures, $year, $previousYear)
            : collect($districts)->groupBy('region')->map(fn ($inRegion) => [
                'districts' => $inRegion->count(),
                'totals' => $this->sum($inRegion->pluck('totals')),
                'previous' => $inRegion->contains(fn (array $district) => $district['previous'] === null) ? null : $this->sum($inRegion->pluck('previous')),
            ]);

        $country = $figures['Malaysia|All'] ?? [];

        return response()->json([
            'year' => $year,
            'years' => $years,
            'previousYear' => $previousYear,
            'byState' => $byState,
            // For a year by state: the regions counted under another's, like Labuan (MY-15) under Sabah (MY-12).
            'countedUnder' => (object) ($byState ? StateCrime::countedUnder() : []),
            'categories' => config('map.crime.categories'),
            'types' => config('map.crime.types'),
            'credit' => $byState ? 'Crime by state: '.config('map.by_state.source') : config('map.crime.credit'),
            'about' => $byState ? null : config('map.crime.about'),
            'national' => isset($country[$year]) ? [
                'totals' => $this->totals($country[$year]),
                'previous' => $previousYear === null ? null : $this->totals($country[$previousYear] ?? []),
            ] : null,
            'regions' => (object) collect($regions)->all(),
            'districts' => $districts,
        ]);
    }

    /**
     * Each region's totals for a year with figures by state only: its state's, and its state's the year before to
     * compare with, and the regions counted in it (Sabah's include Labuan). Without police districts, "districts" is
     * null.
     *
     * @param  array<string, array<int, array<string, array<string, int>>>>  $figures  [State|District][Year][Category][Type]
     * @return array<string, array<string, mixed>>
     */
    private function stateRegions(array $figures, int $year, ?int $previousYear): array
    {
        $counted = collect(StateCrime::countedUnder());
        $regions = [];

        foreach (StateCrime::regions() as $state => $code) {
            $place = $figures["{$state}|All"] ?? [];

            if (! isset($place[$year])) {
                continue;
            }

            $regions[$code] = [
                'districts' => null,
                'totals' => $this->totals($place[$year]),
                'previous' => $previousYear !== null && isset($place[$previousYear]) ? $this->totals($place[$previousYear]) : null,
                'includes' => $counted->filter(fn (string $under) => $under === $code)->keys()
                    ->map(fn (string $region) => config("map.states.{$region}.name"))->values()->all(),
            ];
        }

        return $regions;
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
     * The police stations to find under the map, by state (region code), each by name: its Id, name, police
     * district, address, phone number, the phone number to call, and where it is on Google Maps and in Waze.
     *
     * @return array<string, list<array{id: int, name: string, district: string, address: ?string, phone: ?string, call: ?string, maps: string, waze: string}>>
     */
    private function stations(): array
    {
        try {
            return PoliceStation::query()->orderBy('Name')->get()
                ->groupBy('Region')
                ->map(fn (Collection $stations) => $stations->map(fn (PoliceStation $station) => [
                    'id' => $station->Id,
                    'name' => $station->Name,
                    'district' => $station->District,
                    'address' => $station->Address,
                    'phone' => $station->Phone,
                    'call' => $station->phoneLink(),
                    'maps' => $station->googleMapsUrl(),
                    'waze' => $station->wazeUrl(),
                ])->values()->all())
                ->all();
        } catch (QueryException) {
            // dbo.PoliceStations isn't there until migrate runs.
            return [];
        }
    }

    /**
     * Each category's total from one place and year's figures: its "all" row, or its crime types added up.
     *
     * @param  array<string, array<string, int>>  $figures
     * @return array<string, int>
     */
    private function totals(array $figures): array
    {
        $totals = [];

        foreach (array_keys(config('map.crime.categories')) as $category) {
            $types = $figures[$category] ?? [];
            $totals[$category] = $types['all'] ?? array_sum($types);
        }

        return $totals;
    }

    /**
     * Several places' totals added up, category by category.
     *
     * @param  Collection<int, array<string, int>>  $totals
     * @return array<string, int>
     */
    private function sum(Collection $totals): array
    {
        $sum = [];

        foreach (array_keys(config('map.crime.categories')) as $category) {
            $sum[$category] = $totals->sum($category);
        }

        return $sum;
    }

    /**
     * Each crime type's number, in the order the popups list them.
     *
     * @param  array<string, array<string, int>>  $figures
     * @return array<string, array<string, int>>
     */
    private function types(array $figures): array
    {
        $types = [];

        foreach (config('map.crime.types') as $category => $labels) {
            foreach (array_keys($labels) as $type) {
                $types[$category][$type] = $figures[$category][$type] ?? 0;
            }
        }

        return $types;
    }
}
