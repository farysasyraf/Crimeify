<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LoginUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(?string $password = 'correct-horse'): User
    {
        return User::create(['Name' => 'Ada Lovelace', 'Username' => 'ada.l', 'Email' => 'ada@example.com', 'Password' => $password]);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/users/create')->assertRedirect('/login');
        $this->get('/menu-items')->assertRedirect('/login');
        $this->post('/users/store', ['name' => 'Sneaky', 'email' => 'sneaky@example.com'])->assertRedirect('/login');

        $this->assertDatabaseCount('Users', 0);
    }

    public function test_guests_opening_the_site_see_the_public_dashboard(): void
    {
        // The site's address only: every other page of the app still asks them to log in.
        $this->get('/')->assertRedirect('/public/dashboard');
        $this->get('/?search=ada')->assertRedirect('/public/dashboard');
        $this->getJson('/')->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_login_page_shows_the_form_without_the_menu(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Log in')
            // One box for the email or the username.
            ->assertSee('<label for="login">Email or username</label>', false)
            ->assertSee('<input type="text" id="login" name="login" value="" required autofocus maxlength="255" autocomplete="username"', false)
            ->assertSee('name="password"', false)
            ->assertDontSee('Manage menu');
    }

    public function test_login_page_has_the_video_behind_it_and_other_guest_pages_dont(): void
    {
        foreach (['videos/login-backdrop.mp4', 'images/login-backdrop.jpg', 'js/login-backdrop.js'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        // Behind the top bar and the form, silent, looping and hidden from screen readers; the script gives it its
        // video, so without JavaScript, or asking for less motion or to save data, only the still frame shows.
        $this->get('/login')->assertSeeInOrder([
            '<body class="has-backdrop">',
            '<div class="login-backdrop" aria-hidden="true">',
            '<video class="login-backdrop-video" muted loop playsinline preload="none" tabindex="-1"',
            'poster="'.versioned_asset('images/login-backdrop.jpg').'" data-src="'.versioned_asset('videos/login-backdrop.mp4').'"></video>',
            '<button type="button" class="login-backdrop-toggle" aria-label="Pause the background video" data-backdrop-toggle hidden>',
            '<script src="'.versioned_asset('js/login-backdrop.js').'" defer></script>',
            '<header class="topbar">',
            'name="password"',
        ], false);

        $this->get('/no-such-page')->assertNotFound()->assertDontSee('login-backdrop', false);
    }

    public function test_user_can_log_in_with_the_right_password(): void
    {
        $user = $this->makeUser();

        // The dashboard is the page to start from.
        $this->post('/login', ['login' => 'ada@example.com', 'password' => 'correct-horse'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->get('/users')->assertSee('Ada Lovelace')->assertSee('Log out');
    }

    public function test_user_can_log_in_with_their_username_instead_of_their_email(): void
    {
        $user = $this->makeUser();

        $this->post('/login', ['login' => 'ada.l', 'password' => 'correct-horse'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // In capitals or not, with spaces around it or not.
        $this->post('/logout');
        $this->post('/login', ['login' => ' Ada.L ', 'password' => 'correct-horse'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // The username with the wrong password, or a username no one has, is as wrong as a wrong email.
        $this->post('/logout');
        $this->from('/login')->post('/login', ['login' => 'ada.l', 'password' => 'wrong-horse'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login' => 'The email, username or password is incorrect.']);
        $this->post('/login', ['login' => 'ada', 'password' => 'correct-horse'])
            ->assertSessionHasErrors(['login' => 'The email, username or password is incorrect.']);
        $this->assertGuest();
    }

    public function test_login_returns_to_the_page_you_asked_for(): void
    {
        $this->makeUser();

        $this->get('/menu-items')->assertRedirect('/login');

        $this->post('/login', ['login' => 'ada@example.com', 'password' => 'correct-horse'])
            ->assertRedirect('/menu-items');
    }

    public function test_opening_the_site_and_logging_in_starts_at_the_dashboard(): void
    {
        $this->makeUser();

        // The public dashboard first, then Log in: logging in goes to the app's dashboard, not back to the site's address.
        $this->get('/')->assertRedirect('/public/dashboard');
        $this->get('/login')->assertOk();

        $this->post('/login', ['login' => 'ada@example.com', 'password' => 'correct-horse'])
            ->assertRedirect('/dashboard');

        // Logged in, the site's address leads to the dashboard too, and the users list is at /users.
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/users')->assertOk()->assertSee('<h1>Users</h1>', false);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->makeUser();

        $this->from('/login')
            ->post('/login', ['login' => 'ada@example.com', 'password' => 'wrong-horse'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login' => 'The email, username or password is incorrect.'])
            ->assertSessionHasInput('login', 'ada@example.com')
            ->assertSessionMissing('_old_input.password');

        $this->assertGuest();
    }

    public function test_unknown_email_and_users_without_a_password_cannot_log_in(): void
    {
        $this->makeUser(password: null);

        $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'correct-horse'])
            ->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'ada@example.com', 'password' => 'correct-horse'])
            ->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'ada.l', 'password' => 'correct-horse'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_requires_email_or_username_and_password(): void
    {
        $this->post('/login', ['login' => '', 'password' => ''])
            ->assertSessionHasErrors(['login' => 'Type your email or username.', 'password']);
    }

    public function test_repeated_failures_lock_the_account_out_for_a_minute(): void
    {
        $this->makeUser();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['login' => 'ada@example.com', 'password' => 'wrong-horse']);
        }

        $this->post('/login', ['login' => 'ada@example.com', 'password' => 'correct-horse'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertStringStartsWith('Too many login attempts.', session('errors')->first('login'));
    }

    public function test_logged_in_users_are_sent_away_from_the_login_page(): void
    {
        $this->actingAs($this->makeUser());

        $this->get('/login')->assertRedirect('/dashboard');
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs($this->makeUser());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/menu-items')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/public/dashboard');
    }

    public function test_seeder_creates_accounts_that_can_log_in_and_skips_existing_ones(): void
    {
        User::create(['Name' => 'Existing Demo', 'Email' => 'demo@myapp.local']);

        $seeder = new LoginUserSeeder;
        $seeder->run();

        $this->assertDatabaseHas('Users', ['Email' => 'demo@myapp.local', 'Name' => 'Existing Demo', 'Password' => null]);
        $this->assertDatabaseCount('Users', 2);

        // A random password of its own, not one anyone could guess, and only the new account gets one.
        $password = $seeder->passwords['admin@myapp.local'];
        $this->assertSame(['admin@myapp.local'], array_keys($seeder->passwords));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $password);
        $this->assertNotSame('secret', $password);

        $this->post('/login', ['login' => 'admin@myapp.local', 'password' => 'secret'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'admin@myapp.local', 'password' => $password])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::firstWhere('Email', 'admin@myapp.local'));

        // Each gets a username from their email, to log in with too.
        $this->assertSame(['demo', 'admin'], User::orderBy('Id')->pluck('Username')->all());
        $this->post('/logout');
        $this->post('/login', ['login' => 'admin', 'password' => $password])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::firstWhere('Username', 'admin'));

        // Seeded again, it shows the passwords made this time: none.
        $this->artisan('db:seed', ['--class' => LoginUserSeeder::class])
            ->expectsOutputToContain('admin@myapp.local already exists, left unchanged.')
            ->doesntExpectOutputToContain('password:')
            ->assertSuccessful();
    }

    public function test_db_seed_on_its_own_creates_the_accounts_with_usernames(): void
    {
        // php artisan db:seed runs DatabaseSeeder, as on a new database (DEPLOY.md, step 7).
        $this->artisan('db:seed')
            ->expectsOutputToContain('admin@myapp.local  username: admin  password:')
            ->assertSuccessful();

        $this->assertSame(['admin', 'demo'], User::orderBy('Id')->pluck('Username')->all());
        $this->assertNotNull(User::firstWhere('Username', 'admin')->CreatedAt);

        // A new database has no one with ADMIN until then: the admin account gets it, so it can open the Routes page.
        $this->assertTrue(User::firstWhere('Username', 'admin')->isAdmin());
        $this->assertFalse(User::firstWhere('Username', 'demo')->isAdmin());
        $this->actingAs(User::firstWhere('Username', 'admin'))->get('/routes')->assertOk();
    }

    public function test_passwords_are_never_stored_in_plain_text(): void
    {
        $user = $this->makeUser();

        $this->assertNotSame('correct-horse', $user->Password);
        $this->assertTrue(Hash::check('correct-horse', $user->Password));
        $this->assertArrayNotHasKey('Password', $user->toArray());
    }
}
