<?php

namespace Tests\Feature;

use App\Console\Commands\PushDataToStaging;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * data:push-to-staging, copying this computer's data to staging's database. Here, "staging" is a second SQLite
 * database in a file, migrated like the real one.
 */
class PushDataToStagingTest extends TestCase
{
    use RefreshDatabase;

    private string $stagingFile;

    /**
     * A photo's bytes, with the kinds of bytes text would mangle.
     */
    private const Photo = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\xff\xfe\x00end";

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingFile = storage_path('framework/testing/staging-'.getmypid().'.sqlite');
        File::put($this->stagingFile, '');
        config(['database.connections.staging' => ['driver' => 'sqlite', 'database' => $this->stagingFile, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('staging');
        Artisan::call('migrate', ['--database' => 'staging', '--force' => true]);

        // Here: two users, one with a photo and a role, the crime figures and a station.
        $this->signIn();
        $ada = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com', 'Password' => 'correct-horse']);
        $ada->roles()->attach(Role::create(['Name' => 'JB_PENTADBIRAN'])->Id);
        UserPhoto::saveFor($ada, self::Photo, 'image/png');
        DB::table('PoliceDistricts')->insert(['State' => 'Johor', 'Name' => 'Muar', 'Region' => 'MY-01', 'Latitude' => 2.04, 'Longitude' => 102.57]);
        DB::table('CrimeStats')->insert([
            ['State' => 'Johor', 'District' => 'Muar', 'Category' => 'assault', 'Type' => 'murder', 'Year' => 2023, 'Crimes' => 3],
            ['State' => 'Johor', 'District' => 'Muar', 'Category' => 'property', 'Type' => 'burglary', 'Year' => 2023, 'Crimes' => 41],
        ]);
        DB::table('PoliceStations')->insert(['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'IPD Muar', 'Address' => 'Jalan Petri', 'Phone' => '06-952 2222']);

        // Staging: a user of its own, logged in, with a password-reset link, and other figures.
        DB::connection('staging')->table('Users')->insert(['Id' => 50, 'Name' => 'Old', 'Email' => 'old@staging.test', 'Username' => 'old']);
        DB::connection('staging')->table('sessions')->insert(['id' => 'abc', 'user_id' => 50, 'payload' => '', 'last_activity' => time()]);
        DB::connection('staging')->table('PasswordResetTokens')->insert(['email' => 'old@staging.test', 'token' => 'x']);
        DB::connection('staging')->table('CrimeStats')->insert(['State' => 'Kedah', 'District' => 'Kulim', 'Category' => 'assault', 'Type' => 'rape', 'Year' => 2022, 'Crimes' => 9]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('staging');
        File::delete($this->stagingFile);

        parent::tearDown();
    }

    /**
     * Each of the tables' rows, in Id order where there's one, as the database has them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(?string $connection, array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::connection($connection)->table($table)->get()
            ->map(fn ($row) => (array) $row)->sortBy(fn (array $row) => json_encode($row))->values()->all()])->all();
    }

    public function test_it_replaces_stagings_data_with_this_databases_keeping_every_id(): void
    {
        $tables = array_merge(...array_values(PushDataToStaging::Groups));

        $this->artisan('data:push-to-staging', ['--force' => true])
            ->expectsOutputToContain("Staging's users, passwords and roles become this database's")
            ->expectsOutput("Done: staging has this database's users, crime, stations.")
            ->assertSuccessful();

        // Row for row, Ids and password hashes too, so the same logins work and rows still find each other.
        $this->assertEquals($this->rows(null, $tables), $this->rows('staging', $tables));
        $this->assertSame(self::Photo, DB::connection('staging')->table('UserPhotos')->value('Photo'));

        // Staging's own user is gone, and with it its login and its password-reset link.
        $this->assertFalse(DB::connection('staging')->table('Users')->where('Email', 'old@staging.test')->exists());
        $this->assertSame(0, DB::connection('staging')->table('sessions')->count());
        $this->assertSame(0, DB::connection('staging')->table('PasswordResetTokens')->count());
    }

    public function test_times_with_more_than_milliseconds_fit_a_datetime_column(): void
    {
        // As MyAppDB's Users.CreatedAt is a datetime2, with seven digits, where a new database has a datetime.
        DB::table('Users')->where('Email', 'ada@example.com')->update(['CreatedAt' => '2026-09-24 08:36:55.0270121']);

        $this->artisan('data:push-to-staging', ['--only' => ['users'], '--force' => true])->assertSuccessful();

        $this->assertSame('2026-09-24 08:36:55.027', DB::connection('staging')->table('Users')->where('Email', 'ada@example.com')->value('CreatedAt'));
    }

    public function test_only_the_groups_asked_for_are_copied(): void
    {
        $this->artisan('data:push-to-staging', ['--only' => ['crime'], '--force' => true])
            ->doesntExpectOutputToContain("Staging's users")
            ->expectsOutput("Done: staging has this database's crime.")
            ->assertSuccessful();

        $this->assertEquals($this->rows(null, PushDataToStaging::Groups['crime']), $this->rows('staging', PushDataToStaging::Groups['crime']));

        // Staging's users, logins and stations are as they were.
        $this->assertSame(['old@staging.test'], DB::connection('staging')->table('Users')->pluck('Email')->all());
        $this->assertSame(1, DB::connection('staging')->table('sessions')->count());
        $this->assertSame(0, DB::connection('staging')->table('PoliceStations')->count());
    }

    public function test_it_asks_first_and_changes_nothing_when_told_no(): void
    {
        $before = $this->rows('staging', ['Users', 'CrimeStats', 'sessions']);

        $this->artisan('data:push-to-staging')
            ->expectsOutputToContain('From: :memory:')
            ->expectsConfirmation("Replace these tables on staging with this database's?", 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertSuccessful();

        $this->assertEquals($before, $this->rows('staging', ['Users', 'CrimeStats', 'sessions']));
    }

    public function test_it_stops_before_changing_anything_when_staging_isnt_ready(): void
    {
        $before = $this->rows('staging', ['Users', 'CrimeStats']);

        // A group there isn't.
        $this->artisan('data:push-to-staging', ['--only' => ['photos'], '--force' => true])
            ->expectsOutput("There's no group photos. The groups are users, crime, stations.")
            ->assertFailed();

        // Staging a migration behind.
        DB::connection('staging')->table('migrations')->where('migration', '2026_10_06_000000_add_dashboard_crime_data_and_map_to_menu')->delete();
        $this->artisan('data:push-to-staging', ['--force' => true])
            ->expectsOutputToContain("Staging hasn't run 1 migration(s) yet, like 2026_10_06_000000_add_dashboard_crime_data_and_map_to_menu.")
            ->assertFailed();

        $this->assertEquals($before, $this->rows('staging', ['Users', 'CrimeStats']));

        // Settings that name this same database.
        config(['database.connections.staging' => config('database.connections.sqlite')]);
        DB::purge('staging');
        $this->artisan('data:push-to-staging', ['--force' => true])
            ->expectsOutputToContain("The STAGING_DB_* settings name this computer's own database")
            ->assertFailed();

        // No settings at all.
        config(['database.connections.staging.database' => null]);
        DB::purge('staging');
        $this->artisan('data:push-to-staging', ['--force' => true])
            ->expectsOutputToContain("Staging's database isn't set")
            ->assertFailed();
    }
}
