<?php

namespace Modules\Map\Support;

use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use RuntimeException;

// Crime by state, for the years data.gov.my has no police district figures for yet, from the Royal Malaysia Police's
// crime index tables (config('map.by_state')). They go in dbo.CrimeStats the way data.gov.my's state and Malaysia
// totals do, as police district "All", so the map and dashboard read them like any year. A year with police district
// figures always keeps those; these only fill the years without.
class StateCrime
{
    /**
     * The file's columns, in order.
     */
    private const Columns = ['state', 'category', 'type', 'year', 'crimes'];

    /**
     * Rows per insert, so each stays under SQL Server's limit of 2100 values per query.
     */
    private const Chunk = 300;

    /**
     * Add the file's figures for each year dbo.CrimeStats has no police district figures for, in place of what was
     * added for those years before, with each state's category totals and Malaysia's added up from them.
     * Returns the years added.
     *
     * @return list<int>
     */
    public static function load(): array
    {
        $figures = self::read();
        $years = array_values(array_diff(array_unique(array_column($figures, 'year')), self::districtYears()));
        sort($years);

        if ($years === []) {
            return [];
        }

        // [State][Year][Category][Type] => crimes, then each category's total ("all"), for each state and Malaysia.
        $counts = [];
        foreach ($figures as $figure) {
            if (in_array($figure['year'], $years, true)) {
                $counts[$figure['state']][$figure['year']][$figure['category']][$figure['type']] = $figure['crimes'];
                $national = &$counts['Malaysia'][$figure['year']][$figure['category']][$figure['type']];
                $national = ($national ?? 0) + $figure['crimes'];
                unset($national);
            }
        }

        $rows = [];
        foreach ($counts as $state => $byYear) {
            foreach ($byYear as $year => $byCategory) {
                foreach ($byCategory as $category => $byType) {
                    foreach ($byType + ['all' => array_sum($byType)] as $type => $crimes) {
                        $rows[] = ['State' => $state, 'District' => 'All', 'Category' => $category, 'Type' => $type, 'Year' => $year, 'Crimes' => $crimes];
                    }
                }
            }
        }

        // Those years have no police district figures, so everything they have came from here before.
        CrimeStat::query()->whereIn('Year', $years)->delete();
        foreach (array_chunk($rows, self::Chunk) as $chunk) {
            CrimeStat::insert($chunk);
        }

        return $years;
    }

    /**
     * The years with police district figures, from data.gov.my or the Crime data page.
     *
     * @return list<int>
     */
    public static function districtYears(): array
    {
        return CrimeStat::query()
            ->where('State', '!=', 'Malaysia')
            ->where('District', '!=', 'All')
            ->distinct()
            ->orderBy('Year')
            ->pluck('Year')
            ->map(fn ($year) => (int) $year)
            ->all();
    }

    /**
     * The years with figures by state only, and no police district's.
     *
     * @return list<int>
     */
    public static function stateOnlyYears(): array
    {
        $years = CrimeStat::query()->distinct()->orderBy('Year')->pluck('Year')->map(fn ($year) => (int) $year)->all();

        return array_values(array_diff($years, self::districtYears()));
    }

    /**
     * The map region each state's figures count under: the one most of its police districts' pins are in, so
     * Sabah's, which include Labuan, count under Sabah (MY-12) and W.P. Kuala Lumpur's, which include Putrajaya,
     * under Kuala Lumpur (MY-14). Without the pins, by name.
     *
     * @return array<string, string> state => region code
     */
    public static function regions(): array
    {
        $byName = collect(config('map.states'))->mapWithKeys(fn (array $region, string $code) => [$region['name'] => $code]);
        $byPins = PoliceDistrict::query()->get(['State', 'Region'])->groupBy('State')
            ->map(fn ($districts) => $districts->countBy('Region')->sortDesc()->keys()->first());

        $regions = [];
        foreach (array_unique(array_column(self::read(), 'state')) as $state) {
            $regions[$state] = $byPins[$state] ?? $byName[preg_replace('/^W\.P\. /', '', $state)] ?? null;
        }

        return array_filter($regions);
    }

    /**
     * The regions whose figures by state count under another's: Labuan's under Sabah and Putrajaya's under Kuala
     * Lumpur, from the police districts whose pins are outside their state's region.
     *
     * @return array<string, string> region code => the region code it's counted under
     */
    public static function countedUnder(): array
    {
        $regions = self::regions();
        $counted = [];

        foreach (PoliceDistrict::query()->get(['State', 'Region']) as $district) {
            $under = $regions[$district->State] ?? null;
            if ($under !== null && $district->Region !== $under) {
                $counted[$district->Region] = $under;
            }
        }

        return $counted;
    }

    /**
     * The file's figures: each state's crimes of a type in a year. The file is in this module, or anywhere given
     * its full path; with none set, there are none.
     *
     * @return list<array{state: string, category: string, type: string, year: int, crimes: int}>
     */
    public static function read(): array
    {
        $path = config('map.by_state.file');

        if (blank($path)) {
            return [];
        }

        $file = is_file($path) ? $path : module_path('Map', $path);
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', file_get_contents($file)), fn (string $line) => trim($line) !== ''));
        $header = str_getcsv(array_shift($lines), escape: '');

        if ($header !== self::Columns) {
            throw new RuntimeException(basename($file)."'s columns should be ".implode(', ', self::Columns).'.');
        }

        $categories = config('map.crime.categories');
        $figures = [];

        foreach ($lines as $number => $line) {
            [$state, $category, $type, $year, $crimes] = array_pad(str_getcsv($line, escape: ''), 5, '');

            if ($state === '' || ! isset($categories[$category]) || ! preg_match('/^[a-z][a-z_]{0,39}$/', $type) || $type === 'all'
                || ! preg_match('/^\d{4}$/', $year) || ! ctype_digit($crimes)) {
                throw new RuntimeException('Line '.($number + 2).' of '.basename($file)." isn't a state, category, crime type, year and number of crimes.");
            }

            $figures[] = ['state' => $state, 'category' => $category, 'type' => $type, 'year' => (int) $year, 'crimes' => (int) $crimes];
        }

        return $figures;
    }
}
