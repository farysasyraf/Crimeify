<?php

namespace Modules\Setup\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Setup\Support\MenuTransfer;

// Saves the menu, the routes and who can see and open each (MenuTransfer) to a JSON file, for menu:import to put
// into another database, like the cloud host's (DEPLOY.md).
class ExportMenu extends Command
{
    protected $signature = 'menu:export
        {file? : Where to save it. Left out, it goes in storage/app/private/menu-exports.}';

    protected $description = 'Save the menu links, the routes and their roles to a file, for menu:import';

    public function handle(): int
    {
        $data = MenuTransfer::export();
        $path = $this->argument('file')
            ?? Storage::disk('local')->path('menu-exports/menu-'.Str::slug($data['database'] ?: 'database').'-'.now()->format('Ymd-His').'.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $this->info(sprintf('Saved %d menu links, %d routes and %d roles from %s to %s',
            count($data['menu']), count($data['routes']), count($data['roles']), $data['database'], $path));
        $this->line('Put them into another database with: php artisan menu:import "'.$path.'"');

        return self::SUCCESS;
    }
}
