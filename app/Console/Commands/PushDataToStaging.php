<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

// Copies the app's data from this computer's database to staging's (the "staging" connection, set with STAGING_DB_*
// in .env), replacing what staging has: all of it, or only some groups of tables with --only. A group holds the
// tables that refer to one another, so none is copied half-way, and it all goes in one transaction, so staging gets
// all of it or none. Never copied: the migrations, logins in progress, the cache and password-reset links.
class PushDataToStaging extends Command
{
    protected $signature = 'data:push-to-staging
        {--only=* : Only these groups: users, crime or stations}
        {--force : Replace staging\'s data without asking first}';

    protected $description = "Copy this database's data to staging's, replacing what staging has";

    /**
     * Each group's tables. users takes the roles with it, and so the menu and routes, which are limited by role; the
     * crime figures and the police stations stand alone.
     */
    public const Groups = [
        'users' => ['Roles', 'Users', 'UserRoles', 'UserPhotos', 'MenuItems', 'MenuItemRoles', 'AppRoutes', 'AppRouteRoles'],
        'crime' => ['PoliceDistricts', 'CrimeStats', 'CrimeDataEdits'],
        'stations' => ['PoliceStations', 'PoliceStationEdits'],
    ];

    /**
     * Cleared on staging when its users are replaced: logins and password-reset links belong to the old ones.
     */
    private const ClearedWithUsers = ['sessions', 'PasswordResetTokens'];

    public function handle(): int
    {
        $groups = $this->option('only') ?: array_keys(self::Groups);
        if ($unknown = array_diff($groups, array_keys(self::Groups))) {
            $this->error('There\'s no group '.implode(', ', $unknown).'. The groups are '.implode(', ', array_keys(self::Groups)).'.');

            return self::FAILURE;
        }

        if (blank(config('database.connections.staging.database'))) {
            $this->error("Staging's database isn't set: add STAGING_DB_HOST, STAGING_DB_DATABASE, STAGING_DB_USERNAME and STAGING_DB_PASSWORD to .env.");

            return self::FAILURE;
        }

        $here = DB::connection();
        $staging = DB::connection('staging');

        if ($this->describe($here) === $this->describe($staging)) {
            $this->error("The STAGING_DB_* settings name this computer's own database ({$this->describe($here)}). Nothing was changed.");

            return self::FAILURE;
        }

        // The same migrations on both, so each table has the same columns on both.
        $ranHere = $here->table('migrations')->pluck('migration')->all();
        $ranStaging = $staging->table('migrations')->pluck('migration')->all();
        if ($missing = array_diff($ranHere, $ranStaging)) {
            $this->error("Staging hasn't run ".count($missing).' migration(s) yet, like '.reset($missing).'. Run php artisan migrate --force against staging first (DEPLOY.md, step 7). Nothing was changed.');

            return self::FAILURE;
        }
        if ($missing = array_diff($ranStaging, $ranHere)) {
            $this->error("This database hasn't run ".count($missing).' migration(s) staging has, like '.reset($missing).'. Run php artisan migrate first. Nothing was changed.');

            return self::FAILURE;
        }

        $tables = collect($groups)->flatMap(fn (string $group) => self::Groups[$group])->all();
        $this->line("From: {$this->describe($here)}");
        $this->line("To:   {$this->describe($staging)}");
        $this->table(['Table', 'Here', 'Staging now'], array_map(
            fn (string $table) => [$table, number_format($here->table($table)->count()), number_format($staging->table($table)->count())],
            $tables,
        ));

        $users = in_array('users', $groups, true);
        if ($users) {
            $this->warn("Staging's users, passwords and roles become this database's, and everyone logged in on staging is logged out.");
        }

        if (! $this->option('force') && ! $this->confirm("Replace these tables on staging with this database's?")) {
            $this->info('Nothing was changed.');

            return self::SUCCESS;
        }

        $staging->transaction(fn () => $this->copy($here, $staging, $tables, $users));

        $this->info('Done: staging has this database\'s '.implode(', ', $groups).'.');

        return self::SUCCESS;
    }

    /**
     * Empty each table on staging, then fill it from here, keeping every Id, so rows still find the ones they refer
     * to. Foreign keys are checked once all of it is in, not row by row, as a row may go in before the one it refers to.
     *
     * @param  list<string>  $tables
     */
    private function copy(Connection $here, Connection $staging, array $tables, bool $users): void
    {
        $sqlServer = $staging->getDriverName() === 'sqlsrv';
        $grammar = $staging->getQueryGrammar();

        if ($sqlServer) {
            foreach ($tables as $table) {
                $staging->unprepared('ALTER TABLE '.$grammar->wrapTable($table).' NOCHECK CONSTRAINT ALL');
            }
        } else {
            $staging->statement('PRAGMA defer_foreign_keys = ON');
        }

        foreach (array_reverse($tables) as $table) {
            $staging->table($table)->delete();
        }

        foreach ($tables as $table) {
            $this->copyTable($here, $staging, $table);
        }

        // WITH CHECK: every row is checked against the keys again, and the transaction undone if one doesn't fit.
        if ($sqlServer) {
            foreach ($tables as $table) {
                $staging->unprepared('ALTER TABLE '.$grammar->wrapTable($table).' WITH CHECK CHECK CONSTRAINT ALL');
            }
        }

        if ($users) {
            foreach (self::ClearedWithUsers as $table) {
                if ($staging->getSchemaBuilder()->hasTable($table)) {
                    $staging->table($table)->delete();
                }
            }
        }
    }

    private function copyTable(Connection $here, Connection $staging, string $table): void
    {
        $sqlServer = $staging->getDriverName() === 'sqlsrv';
        $grammar = $staging->getQueryGrammar();
        $columns = collect($staging->getSchemaBuilder()->getColumns($table));
        $names = $columns->pluck('name')->all();

        // SQL Server's PHP driver sends every string as text, which SQL Server won't turn into bytes (UserPhotos.Photo),
        // so bytes go as hex and SQL Server turns them back, as UserPhoto::saveFor does. Each photo in an insert of its own.
        $binary = $columns->filter(fn (array $column) => in_array(strtolower($column['type_name']), ['varbinary', 'binary', 'image', 'blob'], true))
            ->pluck('name')->all();
        $placeholders = '('.implode(', ', array_map(fn (string $name) => $sqlServer && in_array($name, $binary, true) ? 'CONVERT(varbinary(max), ?, 2)' : '?', $names)).')';

        // A datetime column takes milliseconds at most, but the same column here may be a datetime2 with seven digits:
        // MyAppDB's Users.CreatedAt, older than the migrations, which made it datetime on a new database.
        $datetime = $columns->filter(fn (array $column) => strtolower($column['type_name']) === 'datetime')->pluck('name')->all();

        // At most 1000 rows and 2100 values to an insert, SQL Server's limits.
        $perInsert = $binary !== [] ? 1 : max(1, min(1000, intdiv(2000, count($names))));
        $insert = 'INSERT INTO '.$grammar->wrapTable($table).' ('.implode(', ', array_map(fn (string $name) => $grammar->wrap($name), $names)).') VALUES ';

        // Keeping each Id needs IDENTITY_INSERT, for one table at a time. Not prepared, so it lasts for the session.
        $identity = $sqlServer && $columns->contains('auto_increment', true);
        if ($identity) {
            $staging->unprepared('SET IDENTITY_INSERT '.$grammar->wrapTable($table).' ON');
        }

        try {
            foreach ($here->table($table)->select($names)->cursor()->chunk($perInsert) as $chunk) {
                $rows = $chunk->all();
                $values = [];
                foreach ($rows as $row) {
                    foreach ($names as $name) {
                        $value = $row->{$name};
                        if (is_string($value) && in_array($name, $datetime, true)) {
                            $value = preg_replace('/(\.\d{3})\d+$/', '$1', $value);
                        }
                        $values[] = $sqlServer && $value !== null && in_array($name, $binary, true) ? bin2hex($value) : $value;
                    }
                }

                $staging->insert($insert.implode(', ', array_fill(0, count($rows), $placeholders)), $values);
            }
        } finally {
            if ($identity) {
                $staging->unprepared('SET IDENTITY_INSERT '.$grammar->wrapTable($table).' OFF');
            }
        }
    }

    /**
     * A database by its name and server, to show and to tell two apart.
     */
    private function describe(Connection $connection): string
    {
        return $connection->getDatabaseName().($connection->getConfig('host') ? ' on '.$connection->getConfig('host') : '');
    }
}
