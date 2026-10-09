<?php

namespace Modules\Map\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Map\Entities\Population;
use RuntimeException;

// Loads each state's and federal territory's population by year into dbo.Populations, which the Map page divides
// crime by to shade it per 100,000 people. From this module's file (population-by-state.csv), a file given, or with
// --download, the Department of Statistics Malaysia's latest. All three are DOSM's population_state.csv, the module's
// cut down to the totals: it keeps the rows for both sexes, all ages and all ethnic groups. It replaces what was
// loaded before, so it's safe to run again.
class ImportPopulation extends Command
{
    protected $signature = 'map:import-population
        {file? : A population_state.csv file from DOSM. Leave it out for the one in the Map module.}
        {--download : Download DOSM\'s latest file instead}';

    protected $description = "Load each state's population by year, for the Map page's crime per 100,000 people";

    /**
     * The columns DOSM's file has, in order.
     */
    private const Columns = ['state', 'date', 'sex', 'age', 'ethnicity', 'population'];

    /**
     * Rows per insert, so each stays under SQL Server's limit of 2100 values per query.
     */
    private const Chunk = 300;

    public function handle(): int
    {
        try {
            $rows = $this->read($this->source());
        } catch (RuntimeException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        DB::transaction(function () use ($rows) {
            Population::query()->delete();
            foreach (array_chunk($rows, self::Chunk) as $chunk) {
                Population::insert($chunk);
            }
        });

        $years = array_column($rows, 'Year');
        $regions = array_unique(array_column($rows, 'Region'));
        $this->info(sprintf('Loaded the population of %d regions for %d to %d.', count($regions), min($years), max($years)));

        $missing = array_diff_key(config('map.states'), array_flip($regions));
        if ($missing !== []) {
            $this->warn('No population for '.implode(', ', array_column($missing, 'name')).', so the map shows no crime per 100,000 people for '
                .(count($missing) === 1 ? 'it' : 'them').'.');
        }

        return self::SUCCESS;
    }

    /**
     * The population file's text: the one given, DOSM's latest, or the Map module's.
     */
    private function source(): string
    {
        $file = $this->argument('file');

        if ($file !== null && $this->option('download')) {
            throw new RuntimeException('Give a file or --download, not both.');
        }

        if ($this->option('download')) {
            $url = config('map.population.source');
            $this->line("Downloading {$url}");

            try {
                $response = Http::timeout(120)->get($url);
            } catch (ConnectionException $problem) {
                throw new RuntimeException("Couldn't reach DOSM: {$problem->getMessage()}");
            }

            if ($response->failed()) {
                throw new RuntimeException("Couldn't download the population figures from DOSM (HTTP {$response->status()}).");
            }

            return $response->body();
        }

        $file ??= module_path('Map', config('map.population.file'));

        if (! is_file($file)) {
            throw new RuntimeException("There's no file at {$file}.");
        }

        return file_get_contents($file);
    }

    /**
     * Each region's whole population in each year, as rows for dbo.Populations. DOSM gives thousands, to one decimal.
     *
     * @return list<array{Region: string, Year: int, People: int}>
     */
    private function read(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $csv);
        $header = array_map(fn (string $column) => strtolower(trim($column)), str_getcsv(array_shift($lines) ?? '', escape: ''));

        if ($header !== self::Columns) {
            throw new RuntimeException("That isn't DOSM's population by state file: its columns should be ".implode(', ', self::Columns).'.');
        }

        // DOSM's names, with "W.P." in front of the federal territories', to the map's region codes.
        $regions = collect(config('map.states'))->mapWithKeys(fn (array $region, string $code) => [$region['name'] => $code]);
        $people = [];

        foreach ($lines as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            [$state, $date, $sex, $age, $ethnicity, $population] = array_pad(str_getcsv($line, escape: ''), 6, '');

            // The rest are the population split up, by sex, age or ethnic group.
            if ($sex !== 'both' || $age !== 'overall' || $ethnicity !== 'overall') {
                continue;
            }

            if (! preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $year) || ! is_numeric($population) || $population < 0) {
                throw new RuntimeException('Line '.($number + 2)." of the population file isn't a state, date and population.");
            }

            $region = $regions[preg_replace('/^W\.P\. /', '', $state)] ?? null;

            if ($region === null) {
                throw new RuntimeException('Line '.($number + 2)." of the population file has {$state}, which isn't one of Malaysia's states and federal territories.");
            }

            $people[$region][(int) $year[1]] = (int) round((float) $population * 1000);
        }

        if ($people === []) {
            throw new RuntimeException('The population file has no totals: rows for both sexes, all ages and all ethnic groups.');
        }

        $rows = [];
        foreach ($people as $region => $byYear) {
            foreach ($byYear as $year => $count) {
                $rows[] = ['Region' => $region, 'Year' => $year, 'People' => $count];
            }
        }

        return $rows;
    }
}
