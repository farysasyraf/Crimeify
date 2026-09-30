<?php

namespace Modules\Map\Console;

use Illuminate\Console\Command;
use Modules\Map\Entities\PoliceStation;

// Adds the police stations the Map page lists under the map from Modules/Map/Database/data/police-stations.csv, one
// for each police district that has no station saved yet. Stations already saved, edited on the Police stations page
// or not, are never changed, so it's safe to run again; a station deleted there comes back if its district is left
// with none.
class ImportPoliceStations extends Command
{
    protected $signature = 'map:import-stations';

    protected $description = 'Add a police station for each police district that has none yet, from police-stations.csv';

    public function handle(): int
    {
        $file = module_path('Map', config('map.stations'));
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', file_get_contents($file)), fn (string $line) => trim($line) !== ''));
        $header = str_getcsv(array_shift($lines), escape: '');
        $regions = array_keys(config('map.states'));

        // Region|district of every district with a station, and region|name of every station, in lowercase.
        $saved = PoliceStation::query()->get(['Region', 'District', 'Name']);
        $districts = $saved->map(fn (PoliceStation $station) => mb_strtolower("{$station->Region}|{$station->District}"))->flip();
        $names = $saved->map(fn (PoliceStation $station) => mb_strtolower("{$station->Region}|{$station->Name}"))->flip();
        $added = 0;

        foreach ($lines as $number => $line) {
            $row = array_combine($header, str_getcsv($line, escape: ''));

            if (! in_array($row['region'], $regions, true) || trim($row['district']) === '' || trim($row['name']) === '') {
                $this->error('Line '.($number + 2).' of '.basename($file)." doesn't have a region code like MY-01, a police district and a name. Nothing was added.");

                return self::FAILURE;
            }

            if ($districts->has(mb_strtolower("{$row['region']}|{$row['district']}")) || $names->has(mb_strtolower("{$row['region']}|{$row['name']}"))) {
                continue;
            }

            PoliceStation::create([
                'Region' => $row['region'],
                'District' => $row['district'],
                'Name' => $row['name'],
                'Address' => $row['address'] !== '' ? $row['address'] : null,
                'Phone' => $row['phone'] !== '' ? $row['phone'] : null,
            ]);
            $names->put(mb_strtolower("{$row['region']}|{$row['name']}"), true);
            $added++;
        }

        $this->info($added === 0
            ? 'Every police district already has a station. Nothing was added.'
            : sprintf('Added %d police %s. Edit them on the Police stations page.', $added, $added === 1 ? 'station' : 'stations'));

        return self::SUCCESS;
    }
}
