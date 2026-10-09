<?php

namespace Modules\Map\Support;

use Illuminate\Database\QueryException;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\Population;

// Crime per 100,000 people, for every region and year, which the Map page shades the regions by and plays year by
// year. Counts mostly show where people live: Selangor, with seven million, always has more crime than Perlis, with
// under 300,000. Dividing by the population shows where crime is common for the people there.
//
// A region's crimes are counted as the map counts them (MapController@crime): its police districts' in a year by
// police district, so Labuan and Putrajaya count on their own; its state's in a year by state only (StateCrime), when
// Sabah's include Labuan's and Kuala Lumpur's Putrajaya's, so those are divided by both regions' people together.
final class CrimeRates
{
    /**
     * Crimes per this many people.
     */
    public const Per = 100000;

    /**
     * How many shades the map has, from the least crime per person to the most.
     */
    public const Shades = 5;

    /**
     * Every year's rates for the map: what can be shaded by (both categories together, or one), the shades' limits,
     * the same for every year so a shade means the same number throughout, and by year, the country's and each
     * region's population and rates. A region without a population or crimes that year has none.
     *
     * @return array{per: int, measures: array<string, string>, scale: array<string, list<float>>, years: array<int, array{national: ?array{people: int, rates: array<string, float>}, regions: array<string, array{people: int, rates: array<string, float>}>}>}
     */
    public static function all(): array
    {
        $years = [];
        $stateYears = StateCrime::stateOnlyYears();
        // In a year by state, the regions counted in each region's figures, like Labuan in Sabah's.
        $counted = $stateYears === [] ? [] : collect(StateCrime::countedUnder())
            ->mapToGroups(fn (string $under, string $region) => [$under => $region])
            ->map(fn ($regions) => $regions->all())
            ->all();
        $population = self::population();

        foreach (self::crimes($stateYears) as $year => $places) {
            $people = $population[$year] ?? [];
            $regions = [];

            foreach ($places as $place => $crimes) {
                if ($place === 'Malaysia') {
                    continue;
                }

                // A region counted under another has no figures of its own; its people are in that one's.
                $covered = [$place, ...(in_array($year, $stateYears, true) ? ($counted[$place] ?? []) : [])];
                $within = array_map(fn (string $region) => $people[$region] ?? null, $covered);

                if (! in_array(null, $within, true) && array_sum($within) > 0) {
                    $regions[$place] = ['people' => array_sum($within), 'rates' => self::rates($crimes, array_sum($within))];
                }
            }

            ksort($regions);

            // The country's people are every region's, so only with all of them.
            $everyone = count(array_intersect_key($people, config('map.states'))) === count(config('map.states')) ? array_sum($people) : 0;

            $years[$year] = [
                'national' => isset($places['Malaysia']) && $everyone > 0 ? ['people' => $everyone, 'rates' => self::rates($places['Malaysia'], $everyone)] : null,
                'regions' => $regions,
            ];
        }

        ksort($years);

        $scale = [];
        foreach (array_keys(self::measures()) as $measure) {
            $scale[$measure] = self::limits(collect($years)->flatMap(fn (array $year) => array_column(array_column($year['regions'], 'rates'), $measure))->all());
        }

        return ['per' => self::Per, 'measures' => self::measures(), 'scale' => $scale, 'years' => $years];
    }

    /**
     * What the map can be shaded by: violent and property crime together, or either, in the page's language.
     *
     * @return array<string, string>
     */
    public static function measures(): array
    {
        return ['all' => __('Violent and property crime')] + array_map(fn (string $label) => __($label), config('map.crime.categories'));
    }

    /**
     * Each region's and the country's ("Malaysia") crimes of each category, by year, counted as the map counts them.
     *
     * @param  list<int>  $stateYears  the years with figures by state only
     * @return array<int, array<string, array<string, int>>>
     */
    private static function crimes(array $stateYears): array
    {
        $categories = array_keys(config('map.crime.categories'));

        try {
            // Each place's total of each category: its "all" row, or its crime types added up, as the popups do.
            $totals = CrimeStat::query()
                ->selectRaw("State, District, Year, Category, SUM(CASE WHEN Type = 'all' THEN Crimes ELSE 0 END) AS AllCrimes, "
                    ."SUM(CASE WHEN Type = 'all' THEN 1 ELSE 0 END) AS HasAll, SUM(CASE WHEN Type <> 'all' THEN Crimes ELSE 0 END) AS TypeCrimes")
                ->whereIn('Category', $categories)
                ->groupBy('State', 'District', 'Year', 'Category')
                ->toBase()
                ->get();
        } catch (QueryException) {
            // dbo.CrimeStats isn't there until migrate runs.
            return [];
        }

        $states = $stateYears === [] ? [] : StateCrime::regions();
        $pins = PoliceDistrict::query()->get(['State', 'Name', 'Region'])->mapWithKeys(fn (PoliceDistrict $district) => [$district->State.'|'.$district->Name => $district->Region]);
        $crimes = [];

        foreach ($totals as $total) {
            $year = (int) $total->Year;
            $byState = in_array($year, $stateYears, true);

            $place = match (true) {
                $total->State === 'Malaysia' => 'Malaysia',
                $byState => $total->District === 'All' ? ($states[$total->State] ?? null) : null,
                default => $total->District === 'All' ? null : ($pins[$total->State.'|'.$total->District] ?? null),
            };

            if ($place === null) {
                continue;
            }

            $count = (int) $total->HasAll > 0 ? (int) $total->AllCrimes : (int) $total->TypeCrimes;
            $crimes[$year][$place][$total->Category] = ($crimes[$year][$place][$total->Category] ?? 0) + $count;
        }

        return $crimes;
    }

    /**
     * Each region's population by year, from dbo.Populations.
     *
     * @return array<int, array<string, int>>
     */
    private static function population(): array
    {
        try {
            return Population::query()->get(['Region', 'Year', 'People'])
                ->groupBy('Year')
                ->map(fn ($regions) => $regions->pluck('People', 'Region')->all())
                ->all();
        } catch (QueryException) {
            // dbo.Populations isn't there until migrate runs.
            return [];
        }
    }

    /**
     * Crimes per 100,000 people: both categories together, then each, to one decimal.
     *
     * @param  array<string, int>  $crimes  by category
     * @return array<string, float>
     */
    private static function rates(array $crimes, int $people): array
    {
        $rates = ['all' => round(array_sum(array_intersect_key($crimes, config('map.crime.categories'))) / $people * self::Per, 1)];

        foreach (array_keys(config('map.crime.categories')) as $category) {
            $rates[$category] = round(($crimes[$category] ?? 0) / $people * self::Per, 1);
        }

        return $rates;
    }

    /**
     * Where each shade ends and the next begins: so each shade has about as many of the regions' rates over all the
     * years as the others, at round numbers (two significant figures), so the legend reads easily.
     *
     * @param  list<float>  $rates
     * @return list<float>
     */
    private static function limits(array $rates): array
    {
        sort($rates);
        $last = count($rates) - 1;

        if ($last < 1) {
            return [];
        }

        $limits = [];
        for ($shade = 1; $shade < self::Shades; $shade++) {
            $at = $last * $shade / self::Shades;
            $below = $rates[(int) floor($at)];
            $value = $below + ($rates[(int) ceil($at)] - $below) * ($at - floor($at));

            // Above the lowest rate, or the first shade would have none.
            if ($value > $rates[0]) {
                $limits[] = (float) round($value, 1 - (int) floor(log10($value)));
            }
        }

        return array_values(array_unique($limits, SORT_REGULAR));
    }
}
