<?php

namespace Modules\Map\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;

// A police district's profile, for someone deciding where to live (AreaController): its crime each year, how that
// has changed beside its state's and Malaysia's, which crimes are most common there and which more common than across
// its state, and its police stations. In plain sentences made by fixed rules from the figures, so each can be checked
// against them. Not a safety score: the figures are crimes reported to the police, for the whole of a police district,
// and without its population, which isn't published for police districts, they say nothing of the chance of crime.
//
// Its "area" to compare with is its region's police districts together, as the map counts a region: Johor's for Batu
// Pahat. Labuan and Putrajaya are regions of one police district each, so theirs is the state the data files them
// under, Sabah and Kuala Lumpur, which then include them.
final class AreaProfile
{
    /**
     * A change smaller than this many percentage points is "about the same".
     */
    private const Tolerance = 3;

    /**
     * A crime type this many percentage points more common here than across the area is worth saying.
     */
    private const Notable = 5;

    /**
     * @param  array<int, array<string, array<string, int>>>  $crimes  the district's: [year][category][type], "all" for a category's total
     * @param  array<int, array<string, array<string, int>>>  $areaCrimes  the area's, the same way
     * @param  array<int, int>  $national  Malaysia's crimes each year
     */
    private function __construct(
        public readonly PoliceDistrict $district,
        public readonly string $area,
        private readonly array $crimes,
        private readonly array $areaCrimes,
        private readonly array $national,
    ) {}

    /**
     * The district's figures, its area's and Malaysia's, for the years with police district figures.
     */
    public static function of(PoliceDistrict $district): self
    {
        $neighbours = PoliceDistrict::query()->where('Region', $district->Region)->get(['State', 'Name']);
        $byState = $neighbours->count() < 2;

        if ($byState) {
            $neighbours = PoliceDistrict::query()->where('State', $district->State)->get(['State', 'Name']);
        }

        $years = StateCrime::districtYears();
        $places = $neighbours->map(fn (PoliceDistrict $neighbour) => $neighbour->State.'|'.$neighbour->Name)->all();

        // [State|District][Year][Category][Type] => crimes, for the area's police districts and Malaysia.
        $counts = [];
        CrimeStat::query()
            ->whereIn('Year', $years)
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereIn('State', $neighbours->pluck('State')->unique())->whereIn('District', $neighbours->pluck('Name')))
                ->orWhere(fn ($query) => $query->where('State', 'Malaysia')->where('District', 'All')))
            ->get(['State', 'District', 'Category', 'Type', 'Year', 'Crimes'])
            ->each(function (CrimeStat $row) use (&$counts) {
                $counts[$row->State.'|'.$row->District][$row->Year][$row->Category][$row->Type] = $row->Crimes;
            });

        $areaCrimes = [];
        foreach (array_intersect_key($counts, array_flip($places)) as $byYear) {
            foreach ($byYear as $year => $byCategory) {
                foreach (self::complete($byCategory) as $category => $byType) {
                    foreach ($byType as $type => $count) {
                        $areaCrimes[$year][$category][$type] = ($areaCrimes[$year][$category][$type] ?? 0) + $count;
                    }
                }
            }
        }

        $crimes = array_map(self::complete(...), $counts[$district->State.'|'.$district->Name] ?? []);
        ksort($crimes);
        ksort($areaCrimes);

        return new self(
            $district,
            $byState ? preg_replace('/^W\.P\. /', '', $district->State) : $district->regionName(),
            $crimes,
            $areaCrimes,
            array_map(fn (array $byCategory) => self::sumAll(self::complete($byCategory)), $counts['Malaysia|All'] ?? []),
        );
    }

    /**
     * The years with the district's figures, oldest first.
     *
     * @return list<int>
     */
    public function years(): array
    {
        return array_keys($this->crimes);
    }

    public function hasFigures(): bool
    {
        return $this->crimes !== [];
    }

    public function firstYear(): ?int
    {
        return array_key_first($this->crimes);
    }

    public function latestYear(): ?int
    {
        return array_key_last($this->crimes);
    }

    /**
     * The district's crimes in a year: violent and property together, or one category.
     */
    public function total(int $year, ?string $category = null): int
    {
        return $category === null ? self::sumAll($this->crimes[$year] ?? []) : ($this->crimes[$year][$category]['all'] ?? 0);
    }

    /**
     * Each year's violent and property crime, oldest first, for the chart and its table.
     *
     * @return list<array{year: int, assault: int, property: int, total: int}>
     */
    public function yearly(): array
    {
        return array_map(fn (int $year) => [
            'year' => $year,
            'assault' => $this->total($year, 'assault'),
            'property' => $this->total($year, 'property'),
            'total' => $this->total($year),
        ], $this->years());
    }

    /**
     * How a count changed from one year to another, in percent: negative is fewer, and 0 only for the same count. Null
     * without a count to compare with.
     */
    public static function change(?int $from, ?int $to): ?float
    {
        return $from === null || $to === null || $from === 0 ? null : ($to - $from) / $from * 100;
    }

    /**
     * A change's size as it reads: "7%", or under 1% for a change that rounds to none.
     */
    public static function size(float $change): string
    {
        return $change !== 0.0 && abs($change) < 0.5 ? __('under 1%') : round(abs($change)).'%';
    }

    /**
     * The latest year against the year before, if there are figures for it.
     */
    public function latestChange(): ?float
    {
        $year = $this->latestYear();

        return $year !== null && isset($this->crimes[$year - 1]) ? self::change($this->total($year - 1), $this->total($year)) : null;
    }

    /**
     * From the first year to the latest: the district's, its area's and Malaysia's changes, in percent.
     *
     * @return array{here: ?float, area: ?float, national: ?float}
     */
    public function longChange(): array
    {
        [$from, $to] = [$this->firstYear(), $this->latestYear()];

        if ($from === null || $from === $to) {
            return ['here' => null, 'area' => null, 'national' => null];
        }

        return [
            'here' => self::change($this->total($from), $this->total($to)),
            'area' => self::change(isset($this->areaCrimes[$from]) ? self::sumAll($this->areaCrimes[$from]) : null, isset($this->areaCrimes[$to]) ? self::sumAll($this->areaCrimes[$to]) : null),
            'national' => self::change($this->national[$from] ?? null, $this->national[$to] ?? null),
        ];
    }

    /**
     * Each year as a share of the first: 100, then 48 for a year with 48% as much crime, for comparing trends of
     * places of any size on one scale.
     *
     * @param  list<int>  $years
     * @return list<?float>
     */
    public function indexed(array $years): array
    {
        $base = $this->total($years[0] ?? 0);

        return array_map(fn (int $year) => $base === 0 || ! isset($this->crimes[$year]) ? null : round($this->total($year) / $base * 100, 1), $years);
    }

    /**
     * Malaysia's crimes each year as a share of the first of these years, as indexed() does a district's.
     *
     * @param  list<int>  $years
     * @return list<?float>
     */
    public function nationalIndexed(array $years): array
    {
        $base = $this->national[$years[0] ?? 0] ?? 0;

        return array_map(fn (int $year) => $base === 0 || ! isset($this->national[$year]) ? null : round($this->national[$year] / $base * 100, 1), $years);
    }

    /**
     * The latest year's crimes by type, most first: each one's count and share of all the district's crime, and its
     * share across the area. Types none of either had are left out.
     *
     * @return list<array{type: string, category: string, label: string, count: int, share: float, areaShare: float}>
     */
    public function mix(): array
    {
        $year = $this->latestYear();

        if ($year === null) {
            return [];
        }

        [$total, $areaTotal] = [$this->total($year), self::sumAll($this->areaCrimes[$year] ?? [])];
        $mix = [];

        foreach (config('map.crime.types') as $category => $labels) {
            foreach ($labels as $type => $label) {
                $count = $this->crimes[$year][$category][$type] ?? 0;
                $areaCount = $this->areaCrimes[$year][$category][$type] ?? 0;

                if ($count > 0 || $areaCount > 0) {
                    $mix[] = [
                        'type' => $type,
                        'category' => $category,
                        'label' => __($label),
                        'count' => $count,
                        'share' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                        'areaShare' => $areaTotal > 0 ? round($areaCount / $areaTotal * 100, 1) : 0.0,
                    ];
                }
            }
        }

        usort($mix, fn (array $a, array $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return $mix;
    }

    /**
     * The district's police stations, by name.
     *
     * @return Collection<int, PoliceStation>
     */
    public function stations(): Collection
    {
        return PoliceStation::query()->where('Region', $this->district->Region)->where('District', $this->district->Name)->orderBy('Name')->get();
    }

    /**
     * What the figures say, in sentences made by fixed rules: the latest year, the change since the first beside the
     * area's and Malaysia's, the most common crimes, and one more common here than across the area, if there is one.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $year = $this->latestYear();

        if ($year === null) {
            return [__('There are no crime figures for :name yet.', ['name' => $this->district->Name])];
        }

        $sentences = [];
        $latest = $this->latestChange();
        $sentences[] = __(':count violent and property crimes in :year', ['count' => number_format($this->total($year)), 'year' => $year])
            .($latest === null ? '' : ', '.self::compared($latest, $year - 1)).'.';

        $long = $this->longChange();
        if ($long['here'] !== null && $long['area'] !== null && $long['national'] !== null) {
            $sentences[] = __('From :from to :to, crime here :here. Across :area it :there, and across Malaysia it :country.', [
                'from' => $this->firstYear(), 'to' => $year, 'area' => $this->area,
                'here' => self::moved($long['here']), 'there' => self::moved($long['area']), 'country' => self::moved($long['national']),
            ]).rtrim(' '.$this->verdict($long['here'], $long['area']));
        }

        $mix = array_values(array_filter($this->mix(), fn (array $type) => $type['count'] > 0));
        if (count($mix) >= 2) {
            $sentences[] = __('The most common crimes here are :first (:firstShare) and :second (:secondShare).', [
                'first' => Str::lower($mix[0]['label']), 'firstShare' => self::percent($mix[0]['share']),
                'second' => Str::lower($mix[1]['label']), 'secondShare' => self::percent($mix[1]['share']),
            ]);
        }

        $notable = collect($mix)->filter(fn (array $type) => $type['share'] - $type['areaShare'] >= self::Notable)->sortByDesc(fn (array $type) => $type['share'] - $type['areaShare'])->first();
        if ($mix !== []) {
            $sentences[] = $notable
                ? __('Compared with :area, more of the crime here is :type: :share, against :areaShare.', [
                    'area' => $this->area, 'type' => Str::lower($notable['label']),
                    'share' => self::percent($notable['share']), 'areaShare' => self::percent($notable['areaShare']),
                ])
                : __('The kinds of crime here are much like those across :area.', ['area' => $this->area]);
        }

        return $sentences;
    }

    /**
     * A share as it reads: "29%", or under 1%.
     */
    public static function percent(float $share): string
    {
        return $share > 0 && $share < 1 ? __('under 1%') : round($share).'%';
    }

    /**
     * "down 7% from 2022", as the map's popups and the link previews say it.
     */
    private static function compared(float $change, int $year): string
    {
        return match (true) {
            $change === 0.0 => __('the same as in :year', ['year' => $year]),
            $change < 0 => __('down :size from :year', ['size' => self::size($change), 'year' => $year]),
            default => __('up :size from :year', ['size' => self::size($change), 'year' => $year]),
        };
    }

    /**
     * "fell 52%", "rose 10%" or "stayed the same".
     */
    private static function moved(float $change): string
    {
        return match (true) {
            $change === 0.0 => __('stayed the same'),
            $change < 0 => __('fell :size', ['size' => self::size($change)]),
            default => __('rose :size', ['size' => self::size($change)]),
        };
    }

    /**
     * How the district's change compares with its area's, when both fell or both rose, or they're about the same.
     * Nothing when one fell and the other didn't: the sentence before has said so.
     */
    private function verdict(float $here, float $area): string
    {
        $replace = ['area' => $this->area];

        return match (true) {
            abs($here - $area) < self::Tolerance => __("That's about the same as across :area.", $replace),
            $here < 0 && $area < 0 => __($here < $area ? "That's a bigger fall than across :area." : "That's a smaller fall than across :area.", $replace),
            $here > 0 && $area > 0 => __($here < $area ? "That's a smaller rise than across :area." : "That's a bigger rise than across :area.", $replace),
            default => '',
        };
    }

    /**
     * Each category's total, its "all" row or its types added up, with its types.
     *
     * @param  array<string, array<string, int>>  $byCategory
     * @return array<string, array<string, int>>
     */
    private static function complete(array $byCategory): array
    {
        $complete = [];

        foreach (array_keys(config('map.crime.categories')) as $category) {
            $types = $byCategory[$category] ?? [];
            $complete[$category] = ['all' => $types['all'] ?? array_sum($types)] + $types;
        }

        return $complete;
    }

    /**
     * Violent and property crime together.
     *
     * @param  array<string, array<string, int>>  $byCategory
     */
    private static function sumAll(array $byCategory): int
    {
        return array_sum(array_map(fn (array $types) => $types['all'] ?? 0, $byCategory));
    }
}
