<?php

namespace Modules\Setup\Console;

use App\Models\AppRoute;
use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Setup\Support\MenuTransfer;

// Replaces this database's menu links and routes with the ones in a file from menu:export (MenuTransfer), after
// saving what's there now, so it can be put back with menu:import too. Roles go by name, and missing ones are added.
class ImportMenu extends Command
{
    protected $signature = 'menu:import
        {file : A file from "php artisan menu:export"}
        {--force : Replace them without asking first}';

    protected $description = "Replace this database's menu links and routes with the ones in a file from menu:export";

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! File::isFile($path)) {
            $this->error("There's no file at {$path}.");

            return self::FAILURE;
        }

        try {
            $data = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new InvalidArgumentException('This isn\'t a file from "php artisan menu:export".');
            }
            MenuTransfer::check($data);
        } catch (JsonException) {
            $this->error("{$path} isn't a JSON file.");

            return self::FAILURE;
        } catch (InvalidArgumentException $problem) {
            $this->error($problem->getMessage().' Nothing was changed.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $here = $connection->getDatabaseName().($connection->getConfig('host') ? ' on '.$connection->getConfig('host') : '');
        $this->line(sprintf('The file: %d menu links and %d routes from %s, saved %s.',
            count($data['menu']), count($data['routes']), $data['database'] ?? 'another database', $data['exported_at'] ?? 'at some point'));
        $this->line(sprintf('Replacing: the %d menu links and %d routes in %s.', MenuItem::count(), AppRoute::count(), $here));

        if (! $this->option('force') && ! $this->confirm("Replace this database's menu links and routes with the file's?")) {
            $this->info('Nothing was changed.');

            return self::SUCCESS;
        }

        // What's here now, to put back with menu:import if the file wasn't the right one.
        $backup = Storage::disk('local')->path('menu-backups/menu-'.Str::slug($connection->getDatabaseName() ?: 'database').'-before-import-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($backup));
        File::put($backup, json_encode(MenuTransfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $done = MenuTransfer::import($data);

        $this->info(sprintf('Done: %d menu links and %d routes.', $done['menu'], $done['routes']));
        if ($done['roles_added'] !== []) {
            $this->line('Roles added, as the file has them: '.implode(', ', $done['roles_added']).'. Give them to users on Edit user.');
        }
        $this->line("What was here before is saved in {$backup}");

        return self::SUCCESS;
    }
}
