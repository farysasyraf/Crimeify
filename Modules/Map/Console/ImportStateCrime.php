<?php

namespace Modules\Map\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Map\Support\StateCrime;
use RuntimeException;

// Loads crime by state from Modules/Map/Database/data/crime-by-state.csv (the Royal Malaysia Police's crime index
// tables) for the years data.gov.my has no police district figures for, 2024 so far, so the map and dashboard show
// them. It never changes a year with police district figures, nor the figures edited on the Crime data page, so it's
// safe to run again, like after adding a year to the file. "map:import-crime" runs it too, after its own figures.
class ImportStateCrime extends Command
{
    protected $signature = 'map:import-state-crime';

    protected $description = 'Load crime by state for the years data.gov.my has no police district figures for';

    public function handle(): int
    {
        try {
            $years = DB::transaction(fn () => StateCrime::load());
        } catch (RuntimeException $problem) {
            $this->error($problem->getMessage());

            return self::FAILURE;
        }

        if ($years === []) {
            $this->info('Every year in the file has police district figures already, which are kept. Nothing was added.');
        } else {
            $this->info('Added crime by state for '.implode(', ', $years).'. The map and dashboard show '.(count($years) === 1 ? 'it' : 'them').' now.');
        }

        return self::SUCCESS;
    }
}
