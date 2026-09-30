<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\AppRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsernameTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_already_there_get_a_username_from_their_email(): void
    {
        // As the migration found MyAppDB: users without usernames.
        $migration = require database_path('migrations/2026_09_30_000003_add_username_to_users_table.php');
        $migration->down();
        foreach (['ada@example.com', 'Ada@work.example', 'jo@example.com', 'Nur.Iman+work@example.com', '_-.x@example.com'] as $email) {
            DB::table('Users')->insert(['Name' => 'Someone', 'Email' => $email]);
        }

        $migration->up();

        // The part before the @, as far as a username allows, then a number if it's taken; at least 3 characters.
        $this->assertSame(['ada', 'ada2', 'userjo', 'nur.imanwork', 'userx'], DB::table('Users')->orderBy('Id')->pluck('Username')->all());

        // And no two the same from now on.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('Users')->insert(['Name' => 'Another', 'Email' => 'another@example.com', 'Username' => 'ada']);
    }

    public function test_a_user_added_without_a_username_gets_one_as_the_migration_gives_them(): void
    {
        $this->assertSame('ada', User::create(['Name' => 'Ada', 'Email' => 'ada@example.com'])->Username);
        $this->assertSame('ada2', User::create(['Name' => 'Ada', 'Email' => 'ada@work.example'])->Username);
        $this->assertSame('userjo', User::create(['Name' => 'Jo', 'Email' => 'jo@example.com'])->Username);

        // Given one, in lowercase.
        $this->assertSame('grace.h', User::create(['Name' => 'Grace', 'Username' => ' Grace.H ', 'Email' => 'grace@example.com'])->Username);
    }

    public function test_add_user_needs_a_username_no_one_has(): void
    {
        $this->signIn();
        User::create(['Name' => 'Siti Aminah', 'Username' => 'siti', 'Email' => 'siti@example.com']);

        $this->get('/users/create')->assertSeeInOrder([
            '<label for="username">Username</label>',
            '<input type="text" id="username" name="username" value="" required minlength="3" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false"',
            'data-username-check="'.route('username.check').'" data-current=""',
            '3 to 30 letters, numbers, dots, dashes or underscores.',
            '<span class="username-status" id="username-status" aria-live="polite" data-username-status hidden></span>',
        ], false);

        $add = fn (?string $username) => $this->from('/users/create')->post('/users/store', ['name' => 'Grace', 'username' => $username, 'email' => 'grace@example.com']);

        $add('')->assertSessionHasErrors(['username' => 'The username field is required.']);
        $add('ab')->assertSessionHasErrors(['username' => 'The username field must be at least 3 characters.']);
        $add(str_repeat('a', 31))->assertSessionHasErrors(['username' => 'The username field must not be greater than 30 characters.']);
        foreach (['grace hopper', 'grace@home', '.grace', 'gráce'] as $username) {
            $add($username)->assertSessionHasErrors(['username' => 'Use letters, numbers, dots, dashes and underscores only, starting with a letter or number.']);
        }
        // Taken, in capitals or not.
        $add('SITI')->assertSessionHasErrors(['username' => "The username 'siti' is already taken."]);
        $this->assertDatabaseMissing('Users', ['Email' => 'grace@example.com']);

        $add('Grace_Hopper-1.0')->assertRedirect('/users');
        $this->assertDatabaseHas('Users', ['Email' => 'grace@example.com', 'Username' => 'grace_hopper-1.0']);
    }

    public function test_my_profile_changes_my_username_and_i_can_log_in_with_it(): void
    {
        $me = $this->signIn(admin: false);
        User::create(['Name' => 'Siti Aminah', 'Username' => 'siti', 'Email' => 'siti@example.com']);

        $this->get('/profile')
            ->assertSeeInOrder(['<dt>Full name</dt><dd>Signed In</dd>', '<dt>Username</dt><dd>userme</dd>', '<dt>Email</dt><dd>me@example.com</dd>'], false)
            ->assertDontSee('js/username-check.js');
        $this->get('/profile?edit=info')
            ->assertSee('<script src="'.versioned_asset('js/username-check.js').'" defer></script>', false)
            ->assertSee('value="userme"', false)
            ->assertSee('data-current="userme"', false);
        $this->assertFileExists(public_path('js/username-check.js'));

        $save = fn (string $username) => $this->from('/profile?edit=info')->put('/profile', ['section' => 'info', 'name' => 'Signed In', 'username' => $username, 'email' => 'me@example.com']);

        // Someone else's is taken; keeping your own isn't.
        $save('Siti')->assertSessionHasErrors(['username' => "The username 'siti' is already taken."]);
        $save('userme')->assertSessionHasNoErrors();

        $save('Me.Again')->assertRedirect('/profile')->assertSessionHas('message', 'Saved your changes.');
        $this->assertSame('me.again', $me->fresh()->Username);
        $this->get('/profile')->assertSee('<dt>Username</dt><dd>me.again</dd>', false);

        $this->post('/logout');
        $this->post('/login', ['login' => 'me.again', 'password' => 'correct-horse'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($me);
    }

    public function test_the_username_field_asks_whether_a_username_is_free_as_it_is_typed(): void
    {
        $this->postJson('/username-check', ['username' => 'anything'])->assertUnauthorized();

        $this->signIn(admin: false);
        User::create(['Name' => 'Siti Aminah', 'Username' => 'siti', 'Email' => 'siti@example.com']);

        $check = fn (mixed $username) => $this->postJson('/username-check', ['username' => $username])->assertOk();

        $check('New.Name ')->assertExactJson(['username' => 'new.name', 'available' => true, 'message' => 'new.name is available.']);
        $check('Siti')->assertExactJson(['username' => 'siti', 'available' => false, 'message' => "The username 'siti' is already taken."]);
        $check('ab')->assertJson(['available' => false, 'message' => 'The username field must be at least 3 characters.']);
        $check('siti aminah')->assertJson(['available' => false, 'message' => 'Use letters, numbers, dots, dashes and underscores only, starting with a letter or number.']);
        $check(['siti'])->assertJson(['username' => '', 'available' => false, 'message' => 'The username field is required.']);

        // Only asked, with the page's CSRF token: it isn't a page, so the menu doesn't offer to link to it.
        $this->get('/username-check')->assertMethodNotAllowed();
        $this->assertNotContains('/username-check', AppRoute::linkablePages()['builtIn']);
    }

    public function test_the_users_list_shows_usernames_and_finds_users_by_them(): void
    {
        $this->signIn();
        User::create(['Name' => 'Siti Aminah', 'Username' => 'kucing', 'Email' => 'sa@example.com']);
        User::create(['Name' => 'Ali Hassan', 'Username' => 'ali', 'Email' => 'ali@example.com']);

        $this->get('/users')->assertSeeInOrder(['<th>Name</th>', '<th>Username</th>', '<th>Email</th>'], false)
            ->assertSeeInOrder(['<td>Siti Aminah</td>', '<td>kucing</td>', 'sa@example.com'], false);

        $this->get('/users?search=KUCING')->assertSee('<td>Siti Aminah</td>', false)->assertDontSee('<td>Ali Hassan</td>', false);
    }
}
