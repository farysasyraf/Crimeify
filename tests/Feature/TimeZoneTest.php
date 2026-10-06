<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Map\Entities\CrimeDataEdit;
use Tests\TestCase;

class TimeZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_keeps_malaysia_time_as_the_database_does(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', config('app.timezone'));

        // 4pm in Malaysia is 8am UTC; the app writes and shows 4pm.
        $this->travelTo(Carbon::parse('2026-09-29 08:00:00', 'UTC'));
        $this->assertSame('2026-09-29 16:00:00', now()->toDateTimeString());

        $this->signIn();
        CrimeDataEdit::create([
            'UserId' => null, 'UserName' => 'Siti Aminah', 'Action' => 'changed', 'Source' => 'page', 'State' => 'Johor',
            'District' => 'Batu Pahat', 'Category' => 'assault', 'Type' => 'murder', 'Year' => 2023, 'OldCrimes' => 2, 'NewCrimes' => 3,
            'CreatedAt' => now(),
        ]);
        $this->get('/crime-data')->assertSeeInOrder(['Update Logs by User', '29 Sep 2026, 16:00', 'Siti Aminah'], false);
    }

    public function test_the_app_fills_in_when_a_record_was_added_in_malaysia_time(): void
    {
        // Not the database's default: Azure SQL's GETDATE() is always UTC, which would show 8am here.
        $this->travelTo(Carbon::parse('2026-09-29 08:00:00', 'UTC'));

        $records = [
            User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']),
            Role::create(['Name' => 'EDITOR']),
            MenuItem::create(['Label' => 'Reports', 'SortOrder' => 9]),
        ];

        foreach ($records as $record) {
            $this->assertSame('2026-09-29 16:00:00', (string) DB::table($record->getTable())->where('Id', $record->Id)->value('CreatedAt'), $record->getTable());
        }

        // One given on purpose is kept.
        $user = User::create(['Name' => 'Bo', 'Email' => 'bo@example.com']);
        $user->forceFill(['CreatedAt' => '2026-01-01 09:00:00'])->save();
        $this->assertSame('2026-01-01 09:00:00', $user->fresh()->CreatedAt->toDateTimeString());
    }

    public function test_the_times_the_app_wrote_in_utc_move_to_malaysia_time(): void
    {
        $user = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);
        DB::table('Users')->where('Id', $user->Id)->update(['LastSeenAt' => '2026-09-29 08:00:00', 'LoggedOutAt' => '2026-09-29 08:05:00']);
        DB::table('CrimeDataEdits')->insert([
            'UserName' => 'Ada', 'Action' => 'added', 'Source' => 'page', 'State' => 'Johor', 'District' => 'Muar',
            'Category' => 'assault', 'Type' => 'rape', 'Year' => 2023, 'NewCrimes' => 1, 'CreatedAt' => '2026-09-29 23:30:00',
        ]);
        $times = fn () => [
            (string) DB::table('Users')->where('Id', $user->Id)->value('LastSeenAt'),
            (string) DB::table('Users')->where('Id', $user->Id)->value('LoggedOutAt'),
            (string) DB::table('CrimeDataEdits')->value('CreatedAt'),
        ];

        $migration = require database_path('migrations/2026_09_30_000005_move_app_times_to_malaysia_time.php');

        // 8 hours on, into the next day where it crosses midnight; the times the database fills in stay.
        $created = (string) DB::table('Users')->where('Id', $user->Id)->value('CreatedAt');
        $migration->up();
        $this->assertSame(['2026-09-29 16:00:00', '2026-09-29 16:05:00', '2026-09-30 07:30:00'], $times());
        $this->assertSame($created, (string) DB::table('Users')->where('Id', $user->Id)->value('CreatedAt'));

        $migration->down();
        $this->assertSame(['2026-09-29 08:00:00', '2026-09-29 08:05:00', '2026-09-29 23:30:00'], $times());
    }
}
