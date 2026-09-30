<?php

namespace Modules\Map\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Map\Entities\CrimeDataEdit;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\StateCrime;
use RuntimeException;

// Loads the Map page's crime figures from data.gov.my's crime by district file, and its police district pins
// from Modules/Map/Database/data/police-districts.csv. Run it again whenever data.gov.my publishes a new year:
// it replaces what was loaded before. It stops if figures were edited on the Crime data page since, unless it's
// given --force. Then it adds crime by state for the years data.gov.my doesn't have yet (StateCrime).
class ImportCrimeData extends Command
{
    protected $signature = 'map:import-crime
        {file? : A crime_district.csv file from data.gov.my. Leave it out to download the latest.}
        {--force : Replace the figures edited on the Crime data page too}';

    protected $description = "Load the Map page's crime figures from data.gov.my and its police district pins";

    /**
     * The columns data.gov.my's file has, in order.
     */
    private const Columns = ['state', 'district', 'category', 'type', 'date', 'crimes'];

    /**
     * Rows per insert, so each stays under SQL Server's limit of 2100 values per query.
     */
    private const Chunk = 300;

    /**
     * The years crime by state was added for, after data.gov.my's figures (StateCrime).
     *
     * @var list<int>
     */
    private array $stateYears = [];

    public function handle(): int
    {
        // Edits made on the Crime data page would be lost, so they're only replaced when asked to be.
        $edits = CrimeDataEdit::count();

        if ($edits > 0 && ! $this->option('force')) {
            $latest = CrimeDataEdit::query()->orderByDesc('Id')->first();
            $this->error(sprintf('%s %s been edited on the Crime data page since the last import, most recently by %s on %s. '
                .'Importing would replace %s, so nothing was changed. To replace %s anyway, run this again with --force.',
                number_format($edits), $edits === 1 ? 'figure has' : 'figures have', $latest->UserName,
                $latest->CreatedAt->format('d M Y, H:i'), $edits === 1 ? 'it' : 'them', $edits === 1 ? 'it' : 'them'));

            return self::FAILURE;
        }

        try {
            $figures = $this->readFigures($this->source());
            $pins = $this->readPins();
        } catch (RuntimeException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        DB::transaction(function () use ($figures, $pins) {
            // The edits are replaced with everything else, so they're no longer edits to keep.
            CrimeDataEdit::query()->delete();

            PoliceDistrict::query()->delete();
            foreach (array_chunk($pins, self::Chunk) as $chunk) {
                PoliceDistrict::insert($chunk);
            }

            CrimeStat::query()->delete();
            foreach (array_chunk($figures['rows'], self::Chunk) as $chunk) {
                CrimeStat::insert($chunk);
            }

            // Then crime by state for the years data.gov.my doesn't have yet, like 2024.
            $this->stateYears = StateCrime::load();
        });

        $this->report($figures, $pins);

        if ($this->stateYears !== []) {
            $this->info('Added crime by state for '.implode(', ', $this->stateYears).', which data.gov.my has no police district figures for yet.');
        }

        if ($edits > 0) {
            $this->warn(sprintf('Replaced %s %s edited on the Crime data page.', number_format($edits), $edits === 1 ? 'figure' : 'figures'));
        }

        return self::SUCCESS;
    }

    /**
     * The crime file's text: the file given, or the latest from data.gov.my.
     */
    private function source(): string
    {
        $file = $this->argument('file');

        if ($file !== null) {
            if (! is_file($file)) {
                throw new RuntimeException("There's no file at {$file}.");
            }

            return file_get_contents($file);
        }

        $url = config('map.crime.source');
        $this->line("Downloading {$url}");

        try {
            $response = Http::timeout(120)->get($url);
        } catch (ConnectionException $problem) {
            throw new RuntimeException("Couldn't reach data.gov.my: {$problem->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException("Couldn't download the crime figures from data.gov.my (HTTP {$response->status()}).");
        }

        return $response->body();
    }

    /**
     * The crime figures as rows for dbo.CrimeStats, with renamed police districts merged under today's names.
     *
     * @return array{rows: list<array<string, int|string>>, merged: array<string, string>, unknownTypes: list<string>}
     */
    private function readFigures(string $csv): array
    {
        $lines = $this->csvRows($csv);
        $header = array_map(fn (string $column) => strtolower(trim($column)), array_shift($lines) ?? []);

        if ($header !== self::Columns) {
            throw new RuntimeException("That isn't data.gov.my's crime by district file: its columns should be "
                .implode(', ', self::Columns).'.');
        }

        $renamed = config('map.crime.renamed');
        $knownTypes = config('map.crime.types');
        $counts = [];
        $merged = [];
        $unknownTypes = [];

        foreach ($lines as $number => $line) {
            if (count($line) !== count(self::Columns)
                || ! preg_match('/^(\d{4})-\d{2}-\d{2}$/', $line[4], $date)
                || ! ctype_digit($line[5])) {
                throw new RuntimeException('Line '.($number + 2)." of the crime file isn't a state, district, category, type, date and number of crimes.");
            }

            [$state, $district, $category, $type] = $line;

            if (isset($renamed["{$state}|{$district}"])) {
                $merged[$district] = $renamed["{$state}|{$district}"];
                $district = $renamed["{$state}|{$district}"];
            }

            if ($type !== 'all' && ! isset($knownTypes[$category][$type])) {
                $unknownTypes["{$category}/{$type}"] = true;
            }

            $key = implode('|', [$state, $district, $category, $type, $date[1]]);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $line[5];
        }

        $rows = [];
        foreach ($counts as $key => $crimes) {
            [$state, $district, $category, $type, $year] = explode('|', $key);
            $rows[] = ['State' => $state, 'District' => $district, 'Category' => $category, 'Type' => $type, 'Year' => (int) $year, 'Crimes' => $crimes];
        }

        if ($rows === []) {
            throw new RuntimeException('The crime file has no figures in it.');
        }

        return ['rows' => $rows, 'merged' => $merged, 'unknownTypes' => array_keys($unknownTypes)];
    }

    /**
     * The police district pins, as rows for dbo.PoliceDistricts.
     *
     * @return list<array<string, float|string>>
     */
    private function readPins(): array
    {
        $file = module_path('Map', config('map.crime.districts'));
        $lines = $this->csvRows(file_get_contents($file));
        $header = array_shift($lines);
        $regions = array_keys(config('map.states'));
        $pins = [];

        foreach ($lines as $number => $line) {
            $pin = array_combine($header, $line);

            if (! in_array($pin['region'], $regions, true) || ! is_numeric($pin['latitude']) || ! is_numeric($pin['longitude'])) {
                throw new RuntimeException('Line '.($number + 2).' of '.basename($file)." doesn't have a region code like MY-01, a latitude and a longitude.");
            }

            $pins[] = ['State' => $pin['state'], 'Name' => $pin['district'], 'Region' => $pin['region'], 'Latitude' => (float) $pin['latitude'], 'Longitude' => (float) $pin['longitude']];
        }

        return $pins;
    }

    /**
     * A CSV file's rows, without blank lines.
     *
     * @return list<list<string>>
     */
    private function csvRows(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', $csv) as $line) {
            if (trim($line) !== '') {
                $rows[] = str_getcsv($line, escape: '');
            }
        }

        return $rows;
    }

    /**
     * Say what was loaded, and what the Map page can't show.
     *
     * @param  array{rows: list<array<string, int|string>>, merged: array<string, string>, unknownTypes: list<string>}  $figures
     * @param  list<array<string, float|string>>  $pins
     */
    private function report(array $figures, array $pins): void
    {
        $years = array_column($figures['rows'], 'Year');
        $this->info(sprintf('Loaded %s crime figures for %d to %d, and %d police district pins.',
            number_format(count($figures['rows'])), min($years), max($years), count($pins)));

        foreach ($figures['merged'] as $old => $new) {
            $this->line("Merged {$old} into {$new}, its name since then.");
        }

        $placed = array_flip(array_map(fn (array $pin) => $pin['State'].'|'.$pin['Name'], $pins));
        $unplaced = collect($figures['rows'])
            ->filter(fn (array $row) => $row['District'] !== 'All' && ! isset($placed[$row['State'].'|'.$row['District']]))
            ->map(fn (array $row) => "{$row['District']} ({$row['State']})")
            ->unique()
            ->values();

        if ($unplaced->isNotEmpty()) {
            $this->warn('No pin for '.$unplaced->implode(', ').', so the map leaves them out. Add them to Modules/Map/'
                .config('map.crime.districts').' and run this again.');
        }

        if ($figures['unknownTypes'] !== []) {
            $this->warn('Crime types the Map page has no label for, so its popups leave them out: '
                .implode(', ', $figures['unknownTypes']).'. Add them to Modules/Map/Config/config.php.');
        }
    }
}
