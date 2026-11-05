<?php

namespace Modules\Map\Support;

use Modules\Map\Entities\PoliceDistrict;

// "Living here" on a police district's profile: what it's like to live in the administrative district its pin is in:
// household income and spending, poverty, unemployment, schools and amenities, beside its state's and Malaysia's. From
// two files in this module (config('map.living')), so it needs no database: district-living.csv, the figures, which
// "php artisan map:update-living" makes, and police-district-areas.csv, which district each police district's pin is
// in. Administrative districts aren't police districts: Petaling takes in five of them, so each says which it is.
final class AreaLiving
{
    /**
     * The measures shown with figures to compare, in order. Each is a percentage or ringgit a month.
     */
    public const Compared = ['income_median', 'expenditure_mean', 'poverty', 'unemployment'];

    /**
     * Each police district's administrative district, by "state|district", the figures, by
     * "state|district|measure|year", and each measure's years, oldest first, by "state|district|measure", once read.
     *
     * @var array{areas: array<string, array{0: string, 1: string}>, figures: array<string, float>, years: array<string, list<int>>}|null
     */
    private static ?array $files = null;

    /**
     * @param  list<string>  $sharedWith  the other police districts in the same administrative district
     */
    private function __construct(
        public readonly string $state,
        public readonly string $area,
        public readonly array $sharedWith,
    ) {}

    /**
     * The district's figures, or null where its administrative district isn't known, like Padawan's.
     */
    public static function of(PoliceDistrict $district): ?self
    {
        $areas = self::files()['areas'];
        $found = $areas[$district->State.'|'.$district->Name] ?? null;

        if ($found === null) {
            return null;
        }

        $others = array_keys(array_filter($areas, fn (array $area) => $area === $found));
        $sharedWith = array_values(array_filter(array_map(fn (string $key) => explode('|', $key, 2)[1], $others), fn (string $name) => $name !== $district->Name));

        return new self($found[0], $found[1], $sharedWith);
    }

    /**
     * The administrative district's name as it reads, without "W.P.".
     */
    public function name(): string
    {
        return self::plain($this->area);
    }

    /**
     * Its state's name as it reads.
     */
    public function stateName(): string
    {
        return self::plain($this->state);
    }

    /**
     * Whether it's a whole state or federal territory, like Perlis or Kuala Lumpur, so a state to compare with says
     * nothing more.
     */
    public function isWholeState(): bool
    {
        return $this->area === $this->state;
    }

    /**
     * The latest year of a measure here, if it has one.
     */
    public function year(string $measure): ?int
    {
        $years = self::files()['years']["{$this->state}|{$this->area}|{$measure}"] ?? [];

        return $years === [] ? null : end($years);
    }

    /**
     * A measure here in a year, by default its latest.
     */
    public function value(string $measure, ?int $year = null): ?float
    {
        $year ??= $this->year($measure);

        return $year === null ? null : (self::files()['figures']["{$this->state}|{$this->area}|{$measure}|{$year}"] ?? null);
    }

    /**
     * A measure across the state, in the same year as here, to compare with.
     */
    public function stateValue(string $measure): ?float
    {
        return self::files()['figures']["{$this->state}|All|{$measure}|{$this->year($measure)}"] ?? null;
    }

    /**
     * A measure across Malaysia, in the same year as here, to compare with.
     */
    public function nationalValue(string $measure): ?float
    {
        return self::files()['figures']["Malaysia|All|{$measure}|{$this->year($measure)}"] ?? null;
    }

    /**
     * The name of a measure in a sentence or a table, in the page's language.
     */
    public static function label(string $measure): string
    {
        return [
            'income_median' => __('Median monthly household income'),
            'expenditure_mean' => __('Average monthly household spending'),
            'poverty' => __('Households in poverty'),
            'unemployment' => __('Unemployment'),
            'piped_water' => __('Piped water'),
            'sanitation' => __('Sanitation'),
            'electricity' => __('Electricity'),
            'schools_primary' => __('Primary schools'),
            'schools_secondary' => __('Secondary schools'),
            'schools_tertiary' => __('Colleges and universities'),
        ][$measure];
    }

    /**
     * A measure's figure as it reads: "RM 7,555", "2.4%", or a dash without one. Its label says ringgit are a month's.
     */
    public static function format(string $measure, ?float $value): string
    {
        if ($value === null) {
            return '–';
        }

        return in_array($measure, ['income_median', 'expenditure_mean'], true)
            ? 'RM '.number_format($value)
            : number_format($value, 1).'%';
    }

    /**
     * Read the two files once.
     *
     * @return array{areas: array<string, array{0: string, 1: string}>, figures: array<string, float>, years: array<string, list<int>>}
     */
    private static function files(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }

        $areas = [];
        foreach (self::rows(config('map.living.areas')) as [$state, $district, $areaState, $area]) {
            $areas["{$state}|{$district}"] = [$areaState, $area];
        }

        // The file has each place's years in order.
        $figures = $years = [];
        foreach (self::rows(config('map.living.file')) as [$state, $district, $measure, $year, $value]) {
            $figures["{$state}|{$district}|{$measure}|{$year}"] = (float) $value;
            $years["{$state}|{$district}|{$measure}"][] = (int) $year;
        }

        return self::$files = ['areas' => $areas, 'figures' => $figures, 'years' => $years];
    }

    /**
     * The rows of a file in this module, without its header.
     *
     * @return list<list<string>>
     */
    private static function rows(string $path): array
    {
        $lines = file(module_path('Map', $path), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(fn (string $line) => str_getcsv($line, escape: ''), array_slice($lines, 1));
    }

    private static function plain(string $name): string
    {
        return preg_replace('/^W\.P\. /', '', $name);
    }
}
