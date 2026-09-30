<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UsersLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 4pm in Malaysia, the app's time zone (8am UTC).
        $this->travelTo(Carbon::parse('2026-09-29 16:00:00', 'Asia/Kuala_Lumpur'));
    }

    public function test_opening_a_page_records_when_a_user_was_last_seen_at_most_once_a_minute(): void
    {
        $me = $this->signIn();
        $this->assertNull($me->fresh()->LastSeenAt);

        $this->get('/users')->assertOk();
        $this->assertSame('2026-09-29 16:00:00', $me->fresh()->LastSeenAt->toDateTimeString());

        $this->travel(40)->seconds();
        $this->get('/users');
        $this->assertSame('2026-09-29 16:00:00', $me->fresh()->LastSeenAt->toDateTimeString());

        $this->travel(30)->seconds();
        $this->get('/users');
        $this->assertSame('2026-09-29 16:01:10', $me->fresh()->LastSeenAt->toDateTimeString());
    }

    public function test_admin_sees_who_is_online_and_when_each_user_was_last_online(): void
    {
        $this->signIn();
        User::create(['Name' => 'Siti Aminah', 'Email' => 'siti@example.com'])->forceFill(['LastSeenAt' => now()->subMinutes(2)])->save();
        User::create(['Name' => 'Ali Hassan', 'Email' => 'ali@example.com'])->forceFill(['LastSeenAt' => now()->subHours(3)])->save();
        // Seen a minute ago, but logged out since.
        User::create(['Name' => 'Ahmad Faiz', 'Email' => 'ahmad@example.com'])->forceFill(['LastSeenAt' => now()->subMinute(), 'LoggedOutAt' => now()->subSeconds(30)])->save();
        User::create(['Name' => 'Nur Iman', 'Email' => 'nur@example.com']);

        $this->get('/users')->assertOk()->assertSeeInOrder([
            '<h2 id="users-log-heading">Users log</h2>',
            '2 of 5 users online now.',
            'Online is having opened a page in the last 5 minutes without logging out since.',
            // Online first, the latest first; then offline, the latest first; then never.
            'Signed In', '<span class="status status-online"><span class="status-dot" aria-hidden="true"></span>Online</span>', 'Now',
            'Siti Aminah', 'Online', 'Now',
            'Ahmad Faiz', '<span class="status status-offline"><span class="status-dot" aria-hidden="true"></span>Offline</span>',
            '<time datetime="2026-09-29T15:59:00+08:00">1 minute ago</time>', '· 29 Sep 2026, 15:59',
            'Ali Hassan', 'Offline', '<time datetime="2026-09-29T13:00:00+08:00">3 hours ago</time>', '· 29 Sep 2026, 13:00',
            'Nur Iman', 'Offline', '<span class="muted">Never</span>',
        ], false);

        // Past 5 minutes without a page, Siti is offline.
        $this->travel(4)->minutes();
        $this->get('/users')->assertSee('1 of 5 users online now.');
    }

    public function test_only_admin_sees_the_users_log(): void
    {
        // The users list is open to everyone logged in, as the Routes page starts it.
        $this->signIn(admin: false);

        $this->get('/users')->assertOk()->assertSee('<h1>Users</h1>', false)->assertDontSee('Users log');
    }

    public function test_logging_out_is_offline_straight_away_and_logging_in_again_online(): void
    {
        $me = $this->signIn();
        $this->get('/users');
        $this->assertTrue($me->fresh()->isOnline());

        $this->post('/logout');
        $this->assertFalse($me->fresh()->isOnline());
        $this->assertSame('2026-09-29 16:00:00', $me->fresh()->LoggedOutAt->toDateTimeString());

        // Back within the minute: seen again at once, not after the minute.
        $this->travel(20)->seconds();
        $this->post('/login', ['login' => 'me@example.com', 'password' => 'correct-horse']);
        $this->get('/dashboard');
        $this->assertTrue($me->fresh()->isOnline());
    }
}
