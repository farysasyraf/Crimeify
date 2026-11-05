<?php

namespace Modules\Map\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Makes Modules/Map/Database/data/district-living.csv, the figures of "Living here" on a police district's profile
// (AreaLiving), from the Department of Statistics Malaysia's and the Ministry of Education's files
// (config('map.living.sources')). Run it when they publish new figures, and commit the file: the app reads it as it
// is, so a release needs no database work. It keeps each measure's latest two years, for the administrative districts,
// and for those years, the states and Malaysia to compare with. The files spell some districts differently, or
// differently from one year to the next, so each name is tidied to join them.
class UpdateLivingFigures extends Command
{
    protected $signature = 'map:update-living';

    protected $description = "Download the figures of the area profiles' Living here section into the Map module's file";

    /**
     * The file's columns. A state's row has district "All", and Malaysia's has state "Malaysia", as dbo.CrimeStats does.
     */
    private const Columns = ['state', 'district', 'measure', 'year', 'value'];

    public function handle(): int
    {
        try {
            $rows = $this->rows($this->download());
        } catch (RuntimeException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        // In this module, or anywhere given its full path.
        $path = is_file(config('map.living.file')) ? config('map.living.file') : module_path('Map', config('map.living.file'));
        $lines = array_map(fn (array $row) => $this->csvLine($row), $rows);
        file_put_contents($path, implode("\n", [implode(',', self::Columns), ...$lines])."\n");

        $districts = collect($rows)->filter(fn (array $row) => $row['district'] !== 'All')->map(fn (array $row) => $row['state'].'|'.$row['district'])->unique()->count();
        $this->info(sprintf('Wrote %s figures for %d districts to %s. Check the changes and commit it.', number_format(count($rows)), $districts, config('map.living.file')));

        return self::SUCCESS;
    }

    /**
     * Each source file's rows, by its name.
     *
     * @return array<string, list<array<string, string>>>
     */
    private function download(): array
    {
        $files = [];

        foreach (config('map.living.sources') as $name => $url) {
            $this->line("Downloading {$url}");

            try {
                $response = Http::timeout(120)->get($url);
            } catch (ConnectionException $problem) {
                throw new RuntimeException("Couldn't reach {$name}: {$problem->getMessage()}");
            }

            if ($response->failed()) {
                throw new RuntimeException("Couldn't download {$name} (HTTP {$response->status()}).");
            }

            $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $response->body()), fn (string $line) => trim($line) !== ''));
            $header = str_getcsv(array_shift($lines) ?? '', escape: '');

            $files[$name] = array_map(fn (string $line) => array_combine($header, array_pad(str_getcsv($line, escape: ''), count($header), '')), $lines);
        }

        return $files;
    }

    /**
     * The file's rows: every measure, then its latest two years, with each district under one name.
     *
     * @param  array<string, list<array<string, string>>>  $files
     * @return list<array{state: string, district: string, measure: string, year: int, value: float|int}>
     */
    private function rows(array $files): array
    {
        $this->require($files, ['hies_district' => ['income_median', 'expenditure_mean', 'poverty'], 'lfs_district' => ['u_rate'], 'hh_access_amenities' => ['piped_water'], 'schools_district' => ['stage', 'schools']]);

        // [State, district, measure, year, value], with the districts' names as the files have them.
        $found = [];
        $add = function (string $state, string $district, string $measure, string $date, string $value) use (&$found) {
            if ($value === '') {
                return;
            }

            if (! is_numeric($value)) {
                throw new RuntimeException("{$measure} has \"{$value}\" for {$district}. Nothing was changed.");
            }

            $found[] = [$state, $district, $measure, (int) substr($date, 0, 4), $value + 0];
        };

        foreach ($files['hies_district'] as $row) {
            foreach (['income_median', 'expenditure_mean', 'poverty'] as $measure) {
                $add($row['state'], $row['district'], $measure, $row['date'], $row[$measure]);
            }
        }
        foreach ($files['hies_state'] as $row) {
            foreach (['income_median', 'expenditure_mean', 'poverty'] as $measure) {
                $add($row['state'], 'All', $measure, $row['date'], $row[$measure]);
            }
        }
        foreach ($files['hh_income'] as $row) {
            $add('Malaysia', 'All', 'income_median', $row['date'], $row['income_median']);
        }
        foreach ($files['hh_poverty'] as $row) {
            $add('Malaysia', 'All', 'poverty', $row['date'], $row['poverty_absolute']);
        }
        foreach ($files['lfs_district'] as $row) {
            $add($row['state'], $row['district'], 'unemployment', $row['date'], $row['u_rate']);
        }
        foreach ($files['lfs_state_sex'] as $row) {
            if ($row['sex'] === 'both') {
                $add($row['state'], 'All', 'unemployment', $row['date'], $row['u_rate']);
            }
        }
        foreach ($files['lfs_year'] as $row) {
            $add('Malaysia', 'All', 'unemployment', $row['date'], $row['u_rate']);
        }
        foreach ($files['hh_access_amenities'] as $row) {
            foreach (['piped_water', 'sanitation', 'electricity'] as $measure) {
                $add($row['state'], str_contains($row['district'], 'All Districts') ? 'All' : $row['district'], $measure, $row['date'], $row[$measure]);
            }
        }

        // Each stage's schools in a district: tertiary has a row for each kind of institution.
        $schools = [];
        foreach ($files['schools_district'] as $row) {
            if ($row['state'] !== 'Malaysia' && ! str_contains($row['district'], 'All Districts')) {
                $key = "{$row['state']}|{$row['district']}|schools_{$row['stage']}|".substr($row['date'], 0, 4);
                $schools[$key] = ($schools[$key] ?? 0) + (int) $row['schools'];
            }
        }
        foreach ($schools as $key => $count) {
            [$state, $district, $measure, $year] = explode('|', $key);
            $add($state, $district, $measure, $year, (string) $count);
        }

        return $this->joined($found);
    }

    /**
     * The figures with each district under one name, kept to each measure's latest two years in the districts, and a
     * single-district state's figures under its one district, where a file gives them only for the state.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: float|int}>  $found
     * @return list<array{state: string, district: string, measure: string, year: int, value: float|int}>
     */
    private function joined(array $found): array
    {
        // Every spelling of a district by its tidy name, then the one to show: the fullest, without any brackets.
        $spellings = [];
        foreach ($found as [$state, $district]) {
            if ($district !== 'All') {
                $spellings[$state.'|'.$this->tidy($state, $district)][] = trim(preg_replace('/\s*\(.*?\)/', '', $this->aliased($state, $district)));
            }
        }
        $names = array_map(function (array $spelled) {
            $spelled = array_unique($spelled);
            usort($spelled, fn (string $a, string $b) => [strlen($b), $a] <=> [strlen($a), $b]);

            return $spelled[0];
        }, $spellings);

        $rows = [];
        foreach ($found as [$state, $district, $measure, $year, $value]) {
            $name = $district === 'All' ? 'All' : $names[$state.'|'.$this->tidy($state, $district)];
            $rows["{$state}|{$name}|{$measure}|{$year}"] = ['state' => $state, 'district' => $name, 'measure' => $measure, 'year' => $year, 'value' => $value];
        }

        // The latest two years with figures for districts, of each measure.
        $years = [];
        foreach ($rows as $row) {
            if ($row['district'] !== 'All') {
                $years[$row['measure']][$row['year']] = true;
            }
        }
        $keep = array_map(function (array $inMeasure) {
            krsort($inMeasure);

            return array_slice(array_keys($inMeasure), 0, 2);
        }, $years);

        $kept = array_filter($rows, fn (array $row) => in_array($row['year'], $keep[$row['measure']] ?? [], true));

        // Perlis, Kuala Lumpur, Labuan and Putrajaya are one district each, and the jobs files give only their state.
        $districtsIn = [];
        foreach ($names as $key => $name) {
            $districtsIn[explode('|', $key)[0]][] = $name;
        }
        foreach ($kept as $row) {
            $own = $districtsIn[$row['state']] ?? [];
            if ($row['district'] === 'All' && count($own) === 1) {
                $kept["{$row['state']}|{$own[0]}|{$row['measure']}|{$row['year']}"] ??= ['district' => $own[0]] + $row;
            }
        }

        usort($kept, fn (array $a, array $b) => [$a['state'], $a['district'], $a['measure'], $a['year']] <=> [$b['state'], $b['district'], $b['measure'], $b['year']]);

        return $kept;
    }

    /**
     * A district's name as config('map.living.aliases') has it, or as it's given.
     */
    private function aliased(string $state, string $district): string
    {
        return config('map.living.aliases')["{$state}|{$district}"] ?? $district;
    }

    /**
     * A name to join a district's spellings by, like "ulu langat" for Hulu Langat and Ulu Langat, and "seberang perai
     * utara" for S.P.Utara.
     */
    private function tidy(string $state, string $district): string
    {
        $name = strtolower($this->aliased($state, $district));
        $name = preg_replace('/^w\.p\.\s*/', '', $name);
        $name = preg_replace('/\(.*?\)/', '', $name);
        $name = str_replace(['s.p.', '&', ' dan '], ['seberang perai ', ' ', ' '], $name);
        $name = preg_replace('/\bhulu\b/', 'ulu', $name);
        $name = preg_replace('/[^a-z ]/', ' ', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Stop rather than write a file with figures missing, if a source's columns have changed.
     *
     * @param  array<string, list<array<string, string>>>  $files
     * @param  array<string, list<string>>  $columns
     */
    private function require(array $files, array $columns): void
    {
        foreach ($columns as $name => $needed) {
            $missing = array_diff($needed, array_keys($files[$name][0] ?? []));

            if ($missing !== []) {
                throw new RuntimeException("{$name} has no ".implode(', ', $missing).' column. Nothing was changed.');
            }
        }
    }

    /**
     * @param  array{state: string, district: string, measure: string, year: int, value: float|int}  $row
     */
    private function csvLine(array $row): string
    {
        $quote = fn (string $text) => str_contains($text, ',') ? '"'.str_replace('"', '""', $text).'"' : $text;

        return implode(',', [$quote($row['state']), $quote($row['district']), $row['measure'], $row['year'], $row['value']]);
    }
}
