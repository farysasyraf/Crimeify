<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetPublicLocale;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Entities\Population;
use Modules\Map\Support\AskCrimeify;
use Modules\Map\Support\CrimeRates;
use Modules\Map\Support\DistrictPreview;
use Modules\Map\Support\StateCrime;

// The Map module's pages.
class MapController extends Controller
{
    /**
     * Malaysia's states and federal territories on an interactive map, with a list of them beside it, and a pin
     * for each police district with its crime figures for a chosen year. Under it, the police stations of a chosen
     * state, with the chosen one's address and phone number.
     */
    public function index(Request $request): View
    {
        return $this->page(public: false, open: $this->opening($request));
    }

    /**
     * The same map for everyone, without logging in, in the public pages' layout (/public/map).
     */
    public function publicIndex(Request $request): View
    {
        return $this->page(public: true, open: $this->opening($request));
    }

    /**
     * A police district's own address, to share (/public/map/johor/batu-pahat): the public map with its pin open, and
     * the Open Graph tags that WhatsApp and the like make the link's preview from, with an image of its chart.
     */
    public function publicDistrict(string $region, string $district): View
    {
        $found = PoliceDistrict::findByPath($region, $district) ?? abort(404);
        $preview = DistrictPreview::of($found);
        $path = $found->path() + $this->language();

        return $this->page(public: true, open: ['kind' => 'district', 'region' => $found->Region, 'name' => $found->Name, 'label' => $found->Name], share: [
            'title' => $preview->title(),
            'description' => $preview->description(),
            'url' => route('public.map.district', $path),
            'image' => route('public.map.district.preview', $path + ['v' => $preview->version()]),
            'alt' => $preview->alt(),
        ]);
    }

    /**
     * A police district's chart as a PNG image, for its link's preview. Its address changes with the figures (?v=),
     * so it can be kept a day.
     */
    public function districtPreview(string $region, string $district): Response
    {
        $found = PoliceDistrict::findByPath($region, $district) ?? abort(404);

        return response(DistrictPreview::of($found)->png(), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * @param  array{kind: string, region: string, label: string, name?: string, id?: int}|null  $open
     * @param  array{title: string, description: string, url: string, image: string, alt: string}|null  $share
     */
    private function page(bool $public, ?array $open = null, ?array $share = null): View
    {
        $regions = collect(config('map.states'))
            ->map(fn (array $region, string $code) => $region + ['code' => $code])
            ->sortBy('name')
            ->values();
        $years = $this->years();

        return view('map::index', [
            'public' => $public,
            'states' => $regions->where('territory', false)->values(),
            'territories' => $regions->where('territory', true)->values(),
            'boundaries' => versioned_asset(config('map.boundaries')),
            // MapLibre GL JS, which map.js loads as a module, and the street map's style: this app's, or another's address.
            'maplibre' => versioned_asset('vendor/maplibre-gl/maplibre-gl.mjs'),
            'style' => preg_match('#^https?://#', config('map.style')) ? config('map.style') : versioned_asset(config('map.style')),
            'years' => $years,
            // The latest year with police district pins, as the dashboard opens at, which a district or station to open
            // needs too.
            'selectedYear' => StateCrime::defaultYear($years),
            'open' => $open,
            'share' => $share,
            'hasPopulation' => $this->hasPopulation(),
            'stations' => $this->stations(),
            // Every police district's pin, whatever the year, for "Near you" (nearby.js): which are nearest the visitor.
            'pins' => $this->pins(),
            'turf' => versioned_asset('vendor/turf/turf.min.js'),
            // The map's AI (map-ask.js), for everyone, with a key to ask Claude with.
            'ai' => AskCrimeify::configured() && $years !== [] ? [
                'explain' => route('public.map.explain', $this->language()),
                'ask' => route('public.map.ask', $this->language()),
            ] : null,
            // The pins' figures come from MapController@crime: in the app, as "map/crime" added on the Routes page;
            // on the public map, from its own address in Routes/web.php.
            // On the public map, in the page's language, even where cookies aren't kept.
            'crimeUrl' => $public
                ? route('public.map.crime', $this->language())
                : (Route::has('map/crime') ? route('map/crime') : null),
        ]);
    }

    /**
     * The police district (?district=johor/batu-pahat) or station (?station=12) to open on the map, as the command
     * palette links to them, if there is one.
     *
     * @return array{kind: string, region: string, label: string, name?: string, id?: int}|null
     */
    private function opening(Request $request): ?array
    {
        // Text, as the palette gives them, not ?district[]=.
        [$district, $station] = [$request->query('district'), $request->query('station')];

        try {
            if (is_string($district) && $district !== '') {
                [$region, $name] = array_pad(explode('/', $district, 2), 2, '');
                $found = PoliceDistrict::findByPath($region, $name);

                return $found ? ['kind' => 'district', 'region' => $found->Region, 'name' => $found->Name, 'label' => $found->Name] : null;
            }

            if (is_string($station) && ctype_digit($station)) {
                $found = PoliceStation::find((int) $station);

                return $found ? ['kind' => 'station', 'region' => $found->Region, 'id' => $found->Id, 'label' => $found->Name] : null;
            }
        } catch (QueryException) {
            // The tables aren't there until migrate runs.
        }

        return null;
    }

    /**
     * On the public pages, the page's language for the addresses they give, so a link keeps it, even where cookies
     * aren't kept. English, the default, needs none.
     *
     * @return array<string, string>
     */
    private function language(): array
    {
        return app()->getLocale() === SetPublicLocale::defaultLocale() ? [] : ['lang' => app()->getLocale()];
    }

    /**
     * One year's crime figures for the map, as JSON: each police district's pin with its totals and crime types,
     * each region's and the country's totals, and the year before's totals to compare with. Takes ?year=, and
     * gives the year the map opens at for a missing or unknown one (StateCrime::defaultYear).
     */
    public function crime(Request $request): JsonResponse
    {
        $years = $this->years();
        $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : StateCrime::defaultYear($years);
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
                // Each crime type the year before, for how one changed, as the map's ripples show it.
                'previousTypes' => $previousYear !== null && isset($figures[$place][$previousYear])
                    ? $this->types($figures[$place][$previousYear])
                    : null,
                // Its own public address, to share, in the app too: whoever it's sent to may not have a login.
                'share' => route('public.map.district', $district->path() + $this->language()),
                // Its profile, for someone deciding where to live or stay (AreaController).
                'profile' => route('public.areas.show', $district->path() + $this->language()),
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
            // In the page's language (SetPublicLocale on the public map).
            'categories' => array_map(fn (string $label) => __($label), config('map.crime.categories')),
            'types' => array_map(fn (array $labels) => array_map(fn (string $label) => __($label), $labels), config('map.crime.types')),
            'credit' => $byState ? __('Crime by state: :source', ['source' => __(config('map.by_state.source'))]) : __(config('map.crime.credit')),
            'about' => $byState ? null : config('map.crime.about'),
            'national' => isset($country[$year]) ? [
                'totals' => $this->totals($country[$year]),
                'previous' => $previousYear === null ? null : $this->totals($country[$previousYear] ?? []),
            ] : null,
            'regions' => (object) collect($regions)->all(),
            'districts' => $districts,
            'mostInADistrict' => $this->mostInADistrict(),
            'rates' => $this->rates(),
        ]);
    }

    /**
     * The most crimes any police district had in a year, of every year with police district figures: of both
     * categories together ("all"), of each category, and of each crime type. The map sizes its bubbles by these, so a
     * size means the same number of crimes in every year.
     *
     * @return array<string, int>
     */
    private function mostInADistrict(): array
    {
        // Each district's total of each category each year: its "all" row, or its crime types added up, as the
        // popups count them.
        $categories = CrimeStat::query()
            ->selectRaw("State, District, Year, Category, MAX(CASE WHEN Type = 'all' THEN Crimes END) AS AllCrimes, SUM(CASE WHEN Type <> 'all' THEN Crimes ELSE 0 END) AS TypeCrimes")
            ->where('State', '<>', 'Malaysia')->where('District', '<>', 'All')
            ->whereIn('Category', array_keys(config('map.crime.categories')))
            ->groupBy('State', 'District', 'Year', 'Category')
            ->toBase()
            ->get();

        $most = ['all' => 0];
        $both = [];
        foreach ($categories as $row) {
            $count = (int) ($row->AllCrimes ?? $row->TypeCrimes);
            $most[$row->Category] = max($most[$row->Category] ?? 0, $count);
            $key = "{$row->State}|{$row->District}|{$row->Year}";
            $both[$key] = ($both[$key] ?? 0) + $count;
        }
        $most['all'] = max([0, ...array_values($both)]);

        $types = CrimeStat::query()
            ->selectRaw('Type, MAX(Crimes) AS Most')
            ->where('State', '<>', 'Malaysia')->where('District', '<>', 'All')->where('Type', '<>', 'all')
            ->groupBy('Type')
            ->toBase()
            ->get();
        foreach ($types as $row) {
            $most[$row->Type] = (int) $row->Most;
        }

        return $most;
    }

    /**
     * Crime per 100,000 people for every year, which the map shades the regions by and plays year by year, with its
     * population's credit. The same in every year's figures, so the map has them all from the first it loads.
     *
     * @return array<string, mixed>
     */
    private function rates(): array
    {
        $rates = CrimeRates::all();

        return [
            ...$rates,
            // Objects keyed by year and region, as the map expects, though there may be none.
            'years' => (object) array_map(fn (array $year) => [...$year, 'regions' => (object) $year['regions']], $rates['years']),
            'credit' => __(config('map.population.credit')),
            'about' => config('map.population.about'),
        ];
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
     * Whether "php artisan map:import-population" has run, which shading the map per 100,000 people needs.
     */
    private function hasPopulation(): bool
    {
        try {
            return Population::query()->exists();
        } catch (QueryException) {
            // dbo.Populations isn't there until migrate runs.
            return false;
        }
    }

    /**
     * Each police district's pin, by name: its name, region, where it is, and its profile's address (AreaController).
     *
     * @return list<array{name: string, region: string, lat: float, lng: float, profile: string}>
     */
    private function pins(): array
    {
        try {
            return PoliceDistrict::query()->orderBy('Name')->get()
                ->map(fn (PoliceDistrict $district) => [
                    'name' => $district->Name,
                    'region' => $district->Region,
                    'lat' => $district->Latitude,
                    'lng' => $district->Longitude,
                    'profile' => route('public.areas.show', $district->path() + $this->language()),
                ])
                ->all();
        } catch (QueryException) {
            // dbo.PoliceDistricts isn't there until migrate runs.
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
