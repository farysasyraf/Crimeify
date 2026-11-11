<?php

namespace Modules\Map\Support;

/**
 * Crimes by type in two or three police districts side by side, as the compare page's dot plot draws them: for each
 * year they all have, each type's share of each district's violent and property crime, most first. A row says which
 * district stands out, if one does, which are small enough to fold away, and which dots sit so close they're drawn as
 * rings round each other. The page draws the latest year; areas-types.js takes the rest to its year slider.
 */
final class TypeComparison
{
    /** Points above every other district's share for a type to stand out, as the profile's sentences have it. */
    private const StandOut = 5.0;

    /** Under this share in every district, a type is folded away, if two or more are. */
    private const Small = 5.0;

    /** Dots closer than this part of the scale would hide each other, so they're drawn as rings. */
    private const Close = 0.02;

    /**
     * @param  array<int, array<string, array{label: string, shares: array<int, float>, counts: array<int, int>}>>  $byYear
     * @param  list<int>  $years
     */
    private function __construct(private readonly int $areas, private readonly array $years, private readonly array $byYear) {}

    /**
     * @param  list<AreaProfile>  $profiles
     * @param  list<int>  $years  the years all of them have
     */
    public static function of(array $profiles, array $years): self
    {
        $byYear = [];

        foreach ($profiles as $at => $profile) {
            foreach ($profile->eachYear() as $year) {
                if (! in_array($year['year'], $years, true)) {
                    continue;
                }

                foreach ($year['mix'] as $type) {
                    $byYear[$year['year']][$type['type']]['label'] = $type['label'];
                    $byYear[$year['year']][$type['type']]['shares'][$at] = (float) $type['share'];
                    $byYear[$year['year']][$type['type']]['counts'][$at] = $type['count'];
                }
            }
        }

        return new self(count($profiles), $years, $byYear);
    }

    /**
     * The axis's end: the most any type has in any district in any year, up to the next 10%, so the dots keep one
     * scale as the slider moves.
     */
    public function scale(): int
    {
        $most = 0.0;

        foreach ($this->byYear as $types) {
            foreach ($types as $type) {
                $most = max($most, ...array_values($type['shares']));
            }
        }

        return (int) min(100, max(10, ceil($most / 10) * 10));
    }

    /**
     * The axis's lines, every 10% up to 40%, beyond that every 20%.
     *
     * @return list<int>
     */
    public function ticks(): array
    {
        return range(0, $this->scale(), $this->scale() > 40 ? 20 : 10);
    }

    /**
     * Every type any of them has in any of the years, by name, the latest year's order first.
     *
     * @return array<string, string>
     */
    public function types(): array
    {
        $types = [];

        foreach (array_reverse($this->years) as $year) {
            foreach ($this->rows($year) as $row) {
                $types[$row['type']] ??= $row['label'];
            }
        }

        return $types;
    }

    /**
     * A year's rows, most first: each type's share and count in each district (none where it had none), the district
     * that stands out, how many dots before each one it's ringed round, and whether it's folded away. Types none of
     * them had that year aren't there.
     *
     * @return list<array{type: string, label: string, shares: list<float>, counts: list<int>, standOut: int|null, rings: list<int>, folded: bool}>
     */
    public function rows(int $year): array
    {
        $close = $this->scale() * self::Close;
        $rows = [];

        foreach ($this->byYear[$year] ?? [] as $type => $figures) {
            $shares = array_map(fn (int $at) => $figures['shares'][$at] ?? 0.0, range(0, $this->areas - 1));
            $counts = array_map(fn (int $at) => $figures['counts'][$at] ?? 0, range(0, $this->areas - 1));

            if (array_sum($counts) === 0) {
                continue;
            }

            $standOut = null;
            foreach ($shares as $at => $share) {
                $others = array_values(array_diff_key($shares, [$at => true]));
                if ($share - max($others) >= self::StandOut) {
                    $standOut = $at;
                }
            }

            $rows[] = [
                'type' => $type,
                'label' => $figures['label'],
                'shares' => $shares,
                'counts' => $counts,
                'standOut' => $standOut,
                'rings' => array_map(fn (int $at) => count(array_filter(array_slice($shares, 0, $at), fn (float $other) => abs($other - $shares[$at]) < $close)), array_keys($shares)),
                'folded' => max($shares) < self::Small,
            ];
        }

        usort($rows, fn (array $a, array $b) => [array_sum($b['shares']), $a['label']] <=> [array_sum($a['shares']), $b['label']]);

        // Folding one row away saves nothing.
        if (count(array_filter($rows, fn (array $row) => $row['folded'])) < 2) {
            $rows = array_map(fn (array $row) => ['folded' => false] + $row, $rows);
        }

        return $rows;
    }

    /**
     * Each year's rows for the slider (areas-types.js), without the names the page has already.
     *
     * @return list<array{year: int, rows: list<array<string, mixed>>}>
     */
    public function forScript(): array
    {
        return array_map(fn (int $year) => [
            'year' => $year,
            'rows' => array_map(fn (array $row) => array_diff_key($row, ['label' => true]), $this->rows($year)),
        ], $this->years);
    }
}
