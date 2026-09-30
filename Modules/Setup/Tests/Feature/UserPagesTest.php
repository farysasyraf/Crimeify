<?php

namespace Modules\Setup\Tests\Feature;

use App\Models\AppRoute;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->signIn();
    }

    public function test_index_lists_users_newest_first(): void
    {
        User::create(['Name' => 'Ada Lovelace', 'Email' => 'ada@example.com']);
        User::create(['Name' => 'Alan Turing', 'Email' => 'alan@example.com']);

        $this->get('/users')
            ->assertOk()
            ->assertSee('3 users in dbo.Users')
            ->assertSeeInOrder([
                '<td class="num muted">1</td>', 'Alan Turing',
                '<td class="num muted">2</td>', 'Ada Lovelace',
                '<td class="num muted">3</td>', 'Signed In',
            ], false)
            ->assertSee(now()->format('d M Y'));
    }

    public function test_index_has_no_login_column(): void
    {
        User::create(['Name' => 'Ada Lovelace', 'Email' => 'ada@example.com']);

        // Whether a user can log in is in the Password box on Edit user.
        $this->get('/users')
            ->assertSeeInOrder(['<th>Roles</th>', '<th>Created</th>'], false)
            ->assertDontSee('<th>Login</th>', false)
            ->assertDontSee('Can log in')
            ->assertDontSee('No password');
    }

    public function test_row_numbers_restart_at_one_when_searching(): void
    {
        User::create(['Name' => 'Ada Lovelace', 'Email' => 'ada@example.com']);
        User::create(['Name' => 'Alan Turing', 'Email' => 'alan@example.com']);

        $this->get('/users?search=lovelace')
            ->assertSeeInOrder(['<td class="num muted">1</td>', 'Ada Lovelace'], false)
            ->assertDontSee('<td class="num muted">2</td>', false);
    }

    /**
     * The users list on the page, without the users log under it, which shows every user whatever the search.
     */
    private function listOn(string $address): string
    {
        return Str::before($this->get($address)->assertOk()->getContent(), '<section class="card users-log"');
    }

    public function test_search_matches_name_or_email(): void
    {
        User::create(['Name' => 'Ada Lovelace', 'Email' => 'ada@example.com']);
        User::create(['Name' => 'Alan Turing', 'Email' => 'turing@example.org']);

        $this->assertStringContainsString('Ada Lovelace', $list = $this->listOn('/users?search=lovelace'));
        $this->assertStringNotContainsString('Alan Turing', $list);
        $this->assertStringContainsString('Alan Turing', $list = $this->listOn('/users?search=example.org'));
        $this->assertStringNotContainsString('Ada Lovelace', $list);
        $this->get('/users?search=nobody')->assertSee('No users match');
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        User::create(['Name' => '100% Real', 'Email' => 'real@example.com']);
        User::create(['Name' => 'Fake', 'Email' => 'fake@example.com']);

        $this->assertStringContainsString('100% Real', $list = $this->listOn('/users?search='.urlencode('%')));
        $this->assertStringNotContainsString('Fake', $list);
        $this->get('/users?search=_')->assertSee('No users match');
        $this->get('/users?search='.urlencode('['))->assertSee('No users match');
    }

    public function test_index_reports_database_errors(): void
    {
        Schema::drop('Users');

        $this->get('/users')
            ->assertOk()
            ->assertSee("Can't reach the database.", false);
    }

    public function test_create_adds_user(): void
    {
        $this->get('/users/create')->assertOk()->assertSee('Add user');

        $this->post('/users/store', ['name' => '  Grace Hopper ', 'username' => 'grace', 'email' => 'grace@example.com'])
            ->assertRedirect('/users')
            ->assertSessionHas('message', 'Added Grace Hopper.');

        $this->assertDatabaseHas('Users', ['Name' => 'Grace Hopper', 'Email' => 'grace@example.com', 'Password' => null]);
        $this->get('/users')->assertSee('Added Grace Hopper.');
    }

    public function test_create_with_password_stores_it_hashed(): void
    {
        $this->post('/users/store', ['name' => 'Grace Hopper', 'username' => 'grace', 'email' => 'grace@example.com', 'password' => 'cobol-1959'])
            ->assertRedirect('/users');

        $stored = User::firstWhere('Email', 'grace@example.com')->Password;

        $this->assertNotSame('cobol-1959', $stored);
        $this->assertTrue(Hash::check('cobol-1959', $stored));
    }

    public function test_create_validates_input(): void
    {
        $this->post('/users/store', ['name' => '', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->post('/users/store', ['name' => str_repeat('a', 101), 'email' => 'a@example.com'])
            ->assertSessionHasErrors('name');

        $this->post('/users/store', ['name' => 'Short', 'username' => 'short', 'email' => 'short@example.com', 'password' => 'abc'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('Users', 1);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);

        $this->post('/users/store', ['name' => 'Another Ada', 'username' => 'another.ada', 'email' => 'ada@example.com'])
            ->assertSessionHasErrors(['email' => "A user with the email 'ada@example.com' already exists."]);

        $this->assertDatabaseCount('Users', 2);
    }

    public function test_edit_updates_user(): void
    {
        $user = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);

        // What's saved, each box with an Edit button; Edit opens the box as a form.
        $this->get("/users/edit/{$user->Id}")
            ->assertOk()
            ->assertSeeInOrder(['<dt>Full name</dt><dd>Ada</dd>', '<dt>Email</dt><dd>ada@example.com</dd>', '<dt>Phone</dt><dd><span class="muted">Not given</span></dd>'], false)
            ->assertSee('href="'.url("/users/edit/{$user->Id}").'?edit=info#profile-info"', false)
            ->assertDontSee('name="name"', false);
        $this->get("/users/edit/{$user->Id}?edit=info")
            ->assertSee('<input type="hidden" name="section" value="info" />', false)
            ->assertSee('value="ada@example.com"', false)
            ->assertSee('href="'.url("/users/edit/{$user->Id}").'">Cancel</a>', false);

        $this->put("/users/update/{$user->Id}", ['section' => 'info', 'name' => 'Ada Lovelace', 'username' => 'ada', 'email' => 'ada@example.com', 'phone' => ' +60 12-345 6789 '])
            ->assertRedirect("/users/edit/{$user->Id}")
            ->assertSessionHas('message', 'Saved changes to Ada Lovelace.');

        $this->assertDatabaseHas('Users', ['Id' => $user->Id, 'Name' => 'Ada Lovelace', 'Phone' => '+60 12-345 6789']);
        $this->get("/users/edit/{$user->Id}")->assertSee('<dt>Phone</dt><dd>+60 12-345 6789</dd>', false);

        // A phone number is digits and the usual marks, and can be taken away.
        $this->put("/users/update/{$user->Id}", ['section' => 'info', 'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com', 'phone' => 'call me'])
            ->assertSessionHasErrors(['phone' => 'Use digits, spaces and + ( ) - . only, like 012-345 6789.']);
        $this->put("/users/update/{$user->Id}", ['section' => 'info', 'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com', 'phone' => '(219) 555-0114'])->assertSessionHasNoErrors();
        $this->put("/users/update/{$user->Id}", ['section' => 'info', 'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com', 'phone' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->Phone);
    }

    public function test_the_password_box_changes_only_the_password(): void
    {
        $user = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com', 'Password' => 'first-password']);

        $this->get("/users/edit/{$user->Id}")
            ->assertSee('<span class="visually-hidden">Set</span>', false)
            ->assertDontSee('name="password"', false);
        $this->get("/users/edit/{$user->Id}?edit=password")
            ->assertSee('<label for="password">New password</label>', false)
            ->assertSee('At least 8 characters.');

        $this->put("/users/update/{$user->Id}", ['section' => 'password', 'password' => ''])
            ->assertSessionHasErrors(['password' => 'Type the new password.']);
        $this->put("/users/update/{$user->Id}", ['section' => 'password', 'password' => 'short'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('first-password', $user->fresh()->Password));

        $this->put("/users/update/{$user->Id}", ['section' => 'password', 'password' => 'second-password'])
            ->assertRedirect("/users/edit/{$user->Id}");
        $this->assertTrue(Hash::check('second-password', $user->fresh()->Password));
        $this->assertSame(['Ada', 'ada@example.com'], [$user->fresh()->Name, $user->fresh()->Email]);

        // Without a password, the box says so.
        $user->update(['Password' => null]);
        $this->get("/users/edit/{$user->Id}")->assertSee("Not set. Without a password, this user can't log in.", false);
    }

    public function test_edit_rejects_another_users_email(): void
    {
        User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);
        $alan = User::create(['Name' => 'Alan', 'Email' => 'alan@example.com']);

        $this->put("/users/update/{$alan->Id}", ['name' => 'Alan', 'username' => 'alan', 'email' => 'ada@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('Users', ['Id' => $alan->Id, 'Email' => 'alan@example.com']);
    }

    public function test_delete_asks_for_confirmation_then_removes_user(): void
    {
        $user = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);

        $this->get("/users/delete/{$user->Id}")->assertOk()->assertSee('ada@example.com');

        $this->delete("/users/destroy/{$user->Id}")
            ->assertRedirect('/users')
            ->assertSessionHas('message', 'Deleted Ada.');

        $this->assertDatabaseMissing('Users', ['Id' => $user->Id]);
    }

    public function test_create_assigns_the_ticked_roles(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $editor = Role::create(['Name' => 'Editor']);
        Role::create(['Name' => 'Viewer']);

        $this->get('/users/create')->assertSeeInOrder(['Admin', 'Editor', 'Viewer']);

        $this->post('/users/store', ['name' => 'Grace', 'username' => 'grace', 'email' => 'grace@example.com', 'roles' => [$admin->Id, $editor->Id]])
            ->assertRedirect('/users');

        $grace = User::firstWhere('Email', 'grace@example.com');
        $this->assertEqualsCanonicalizing(['Admin', 'Editor'], $grace->roles->pluck('Name')->all());
    }

    public function test_edit_shows_current_roles_and_replaces_them_on_save(): void
    {
        $admin = Role::create(['Name' => 'Admin']);
        $editor = Role::create(['Name' => 'Editor']);
        $user = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);
        $user->roles()->attach($admin->Id);

        $this->get("/users/edit/{$user->Id}")
            ->assertSee('<span class="badge badge-role">Admin</span>', false)
            ->assertSee('href="'.url("/users/edit/{$user->Id}").'?edit=roles#profile-roles"', false)
            ->assertDontSee('name="roles[]"', false);
        $this->get("/users/edit/{$user->Id}?edit=roles")
            ->assertSee('value="'.$admin->Id.'" checked', false)
            ->assertDontSee('value="'.$editor->Id.'" checked', false);

        $this->put("/users/update/{$user->Id}", ['section' => 'roles', 'roles' => [$editor->Id]]);
        $this->assertSame(['Editor'], $user->fresh()->roles->pluck('Name')->all());

        // Saving the other boxes, or no box, leaves the roles alone.
        $this->put("/users/update/{$user->Id}", ['section' => 'info', 'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com', 'roles' => [$admin->Id]]);
        $this->put("/users/update/{$user->Id}", ['name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com']);
        $this->assertSame(['Editor'], $user->fresh()->roles->pluck('Name')->all());

        // Unticking every box in the Roles box sends no roles field at all.
        $this->put("/users/update/{$user->Id}", ['section' => 'roles']);
        $this->assertCount(0, $user->fresh()->roles);
        $this->get("/users/edit/{$user->Id}")->assertSee('<p class="profile-note muted">None.</p>', false);
    }

    public function test_roles_must_exist(): void
    {
        $this->post('/users/store', ['name' => 'Grace', 'username' => 'grace', 'email' => 'grace@example.com', 'roles' => [999]])
            ->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('Users', ['Email' => 'grace@example.com']);
    }

    public function test_user_form_explains_when_there_are_no_roles(): void
    {
        // Deleting every role, the ADMIN one the migrations add too, leaves Add user limited to no one, so open it to
        // everyone first, as the Routes page would.
        AppRoute::firstWhere('Path', 'users/create')->update(['OpenToEveryone' => true]);
        AppRoute::registerBehindLogin();
        Role::query()->delete();

        $this->get('/users/create')->assertOk()->assertSee('No roles yet.');
    }

    public function test_index_shows_each_users_roles(): void
    {
        User::create(['Name' => 'Alan', 'Email' => 'alan@example.com']);
        $user = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);
        $user->roles()->attach([Role::create(['Name' => 'Editor'])->Id, Role::create(['Name' => 'Admin'])->Id]);

        $this->get('/users')->assertSeeInOrder([
            'Ada',
            '<span class="badge badge-role">Admin</span>',
            '<span class="badge badge-role">Editor</span>',
            'Alan',
            'None',
            'Signed In',
            '<span class="badge badge-role">ADMIN</span>',
        ], false);
    }

    public function test_deleting_a_user_removes_their_role_links(): void
    {
        $user = User::create(['Name' => 'Ada', 'Email' => 'ada@example.com']);
        $role = Role::create(['Name' => 'Editor']);
        $user->roles()->attach($role->Id);

        $this->get("/users/delete/{$user->Id}")->assertSee('Editor');
        $this->delete("/users/destroy/{$user->Id}");

        $this->assertDatabaseMissing('UserRoles', ['UserId' => $user->Id]);
        $this->assertDatabaseHas('Roles', ['Id' => $role->Id]);
    }

    public function test_you_cannot_delete_your_own_account(): void
    {
        $this->get("/users/delete/{$this->me->Id}")
            ->assertOk()
            ->assertSee("You can't delete the account you're logged in with.", false)
            ->assertDontSee('>Delete user</button>', false);

        $this->delete("/users/destroy/{$this->me->Id}")->assertForbidden();

        $this->assertDatabaseHas('Users', ['Id' => $this->me->Id]);
    }

    public function test_missing_user_returns_not_found(): void
    {
        $this->get('/users/edit/999')->assertNotFound()->assertSee('Not found');
        $this->get('/users/delete/999')->assertNotFound();
        $this->delete('/users/destroy/999')->assertNotFound();
        $this->get('/users/abc/edit')->assertNotFound();
    }
}
