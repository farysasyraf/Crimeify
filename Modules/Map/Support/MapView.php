<?php

namespace Modules\Map\Support;

use Illuminate\Database\QueryException;
use Modules\Map\Entities\CrimeStat;

// A view of the crime map (map.js), as the map's AI reads and sets one: the year, the region chosen ("Malaysia" for
// none), what the states are shaded by (CrimeRates::measures), what the police districts' pins show ("" for where each
// is, or bubbles of a crime: "all", a category or a crime type), and 3D or flat. Ask the map (AskTheMap) gives one for
// the map to go to; What am I looking at? (ExplainView) describes the one shown. Anything else is left out, so a view
// from a browser or from Claude has only what the map can show.
final class MapView
{
    /**
     * The years there are crime figures for, oldest first, as the map has them.
     *
     * @return list<int>
     */
    public static function years(): array
    {
        try {
            return CrimeStat::query()->distinct()->orderBy('Year')->pluck('Year')->map(fn ($year) => (int) $year)->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Each region's code and name, "Malaysia" first.
     *
     * @return array<string, string>
     */
    public static function regions(): array
    {
        return ['Malaysia' => 'Malaysia'] + collect(config('map.states'))->map(fn (array $state) => $state['name'])->sortKeys()->all();
    }

    /**
     * What the pins can show, each with its name: "" for where each police district is, then bubbles of each crime.
     *
     * @return array<string, string>
     */
    public static function crimes(): array
    {
        return [
            '' => __('Where each police district is'),
            'all' => __('Violent and property crime'),
            ...array_map(fn (string $label) => __($label), config('map.crime.categories')),
            ...collect(config('map.crime.types'))->flatMap(fn (array $types) => $types)->map(fn (string $label) => __($label))->all(),
        ];
    }

    /**
     * A view with only what the map can show: each part known, or null where it isn't, or, with $complete, the map's
     * own default for it.
     *
     * @param  array<string, mixed>  $view
     * @return array{year: ?int, region: ?string, shade: ?string, pins: ?string, raised: ?bool}
     */
    public static function clean(array $view, bool $complete = false): array
    {
        $years = self::years();
        $year = is_numeric($view['year'] ?? null) && in_array((int) $view['year'], $years, true) ? (int) $view['year'] : null;
        $region = is_string($view['region'] ?? null) && isset(self::regions()[$view['region']]) ? $view['region'] : null;
        $shade = is_string($view['shade'] ?? null) && isset(CrimeRates::measures()[$view['shade']]) ? $view['shade'] : null;
        $pins = is_string($view['pins'] ?? null) && array_key_exists($view['pins'], self::crimes()) ? $view['pins'] : null;
        $raised = is_bool($view['raised'] ?? null) ? $view['raised'] : null;

        if ($complete) {
            $year ??= StateCrime::defaultYear($years);
            $region ??= 'Malaysia';
            $shade ??= 'all';
            $pins ??= '';
            $raised ??= false;
        }

        return ['year' => $year, 'region' => $region, 'shade' => $shade, 'pins' => $pins, 'raised' => $raised];
    }

    /**
     * Something that changes when the figures do, so what the AI said about the old ones isn't kept.
     */
    public static function version(): string
    {
        try {
            return implode('.', [CrimeStat::query()->count(), CrimeStat::query()->sum('Crimes'), CrimeStat::query()->max('Year') ?? 0]);
        } catch (QueryException) {
            return '0';
        }
    }

    /**
     * Whether every figure in what the AI wrote is one of the numbers it was given (the values, not the names they're
     * given by), as given or rounded, but for the small counting numbers, one to ten, without a % sign, like "the 3
     * police districts", and the 100,000 people crime is counted per.
     *
     * @param  array<mixed>  $given
     */
    public static function backedBy(string $text, array $given): bool
    {
        $known = [100000.0];
        array_walk_recursive($given, function ($value) use (&$known) {
            if (is_int($value) || is_float($value)) {
                $known[] = abs((float) $value);
            }
        });

        preg_match_all('/(?<![\d.,])(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?(\s?%)?/u', $text, $said, PREG_SET_ORDER);

        foreach ($said as $match) {
            $number = (float) (str_replace(',', '', $match[1]).(($match[2] ?? '') !== '' ? '.'.$match[2] : ''));
            $percent = ($match[3] ?? '') !== '';

            if (! $percent && $number >= 1 && $number <= 10 && floor($number) === $number) {
                continue;
            }

            $backed = false;
            foreach ($known as $value) {
                if (abs($value - $number) < 0.051 || round($value) === $number) {
                    $backed = true;
                    break;
                }
            }

            if (! $backed) {
                return false;
            }
        }

        return true;
    }
}
