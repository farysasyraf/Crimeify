<?php

namespace Farysasyraf\SavedRoutes\Console;

use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Console\Command;

/**
 * Drops the cached saved routes, for when the table was changed without its model, as by a migration.
 */
class ClearCacheCommand extends Command
{
    protected $signature = 'saved-routes:clear';

    protected $description = 'Drop the cached saved routes so the next request reads them from the database';

    public function handle(): int
    {
        if (! config('saved-routes.cache.enabled')) {
            $this->components->info('Saved routes aren\'t cached (saved-routes.cache.enabled is off), so there\'s nothing to clear.');

            return self::SUCCESS;
        }

        SavedRoutes::forgetCache();
        $this->components->info('Cleared the cached saved routes.');

        return self::SUCCESS;
    }
}
