<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The times the app wrote in UTC before it kept Malaysia time (config('app.timezone')), as MyAppDB's own defaults
     * already did (GETDATE(), like Users' CreatedAt). Malaysia is UTC+8 all year, with no daylight saving.
     */
    private const Columns = [
        ['Users', 'LastSeenAt'],
        ['Users', 'LoggedOutAt'],
        ['UserPhotos', 'UpdatedAt'],
        ['CrimeDataEdits', 'CreatedAt'],
    ];

    /**
     * Move them 8 hours on, so every time in the database is Malaysia time.
     */
    public function up(): void
    {
        $this->shift(8);
    }

    /**
     * Reverse the migrations: back to UTC, for an app keeping UTC again.
     */
    public function down(): void
    {
        $this->shift(-8);
    }

    private function shift(int $hours): void
    {
        foreach (self::Columns as [$table, $column]) {
            $moved = match (DB::connection()->getDriverName()) {
                'sqlsrv' => DB::raw("DATEADD(hour, {$hours}, [{$column}])"),
                'sqlite' => DB::raw("datetime(\"{$column}\", '".sprintf('%+d', $hours)." hours')"),
                default => DB::raw("\"{$column}\" + INTERVAL '{$hours} hours'"),
            };

            DB::table($table)->whereNotNull($column)->update([$column => $moved]);
        }
    }
};
