<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->ada = User::create(['Name' => 'Ada Lovelace', 'Username' => 'ada.l', 'Email' => 'ada@gmail.com', 'Password' => 'first-password']);
    }

    /**
     * Ask for a link for this email, and give back the token the email carries, if one was sent.
     */
    private function askForLink(string $email = 'ada@gmail.com'): ?string
    {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])->assertRedirect('/forgot-password');

        $token = null;
        Notification::assertSentTo($this->ada, ResetPasswordLink::class, function (ResetPasswordLink $link) use (&$token) {
            $token = $link->token;

            return true;
        });

        return $token;
    }

    public function test_the_login_page_leads_to_forgot_password(): void
    {
        $this->get('/login')->assertSee('<a class="auth-forgot" href="'.route('password.request').'">Forgot password?</a>', false);

        $this->get('/forgot-password')->assertOk()
            ->assertSee('<h1>Forgot password?</h1>', false)
            ->assertSee('<input type="email" id="email" name="email" value="" required autofocus maxlength="255" autocomplete="email"', false);
    }

    public function test_asking_for_a_link_emails_one_and_says_the_same_whoever_asks(): void
    {
        $said = fn ($email) => $this->from('/forgot-password')->followingRedirects()->post('/forgot-password', ['email' => $email]);

        // In capitals or not, a link goes to the account's email, named and with the link to its page.
        $said('ADA@gmail.com')->assertOk()
            ->assertSee('If an account has the email <strong>ada@gmail.com</strong>, a link to choose a new password is on its way to it.', false)
            ->assertSee('Send another link');
        Notification::assertSentTo($this->ada, ResetPasswordLink::class, function (ResetPasswordLink $link) {
            $mail = $link->toMail($this->ada);

            $this->assertSame('Choose a new password for '.config('app.name'), $mail->subject);
            $this->assertSame(url('/reset-password/'.$link->token.'?email=ada%40gmail.com'), $mail->actionUrl);
            $this->assertStringContainsString('ada.l (ada@gmail.com)', implode(' ', $mail->introLines));
            $this->assertStringContainsString('The link works once, for the next 60 minutes.', implode(' ', $mail->outroLines));
            $this->assertSame([['ada@gmail.com' => 'Ada Lovelace']], [$this->ada->routeNotificationFor('mail')]);

            return true;
        });

        // No account, or one that can't log in, gets no email, and hears the same.
        $locked = User::create(['Name' => 'Locked', 'Email' => 'locked@gmail.com']);
        $said('nobody@gmail.com')->assertSee('If an account has the email <strong>nobody@gmail.com</strong>, a link to choose a new password is on its way to it.', false);
        $said('locked@gmail.com')->assertSee('If an account has the email', false);
        Notification::assertNotSentTo($locked, ResetPasswordLink::class);
        Notification::assertSentTimes(ResetPasswordLink::class, 1);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'not an email'])
            ->assertSessionHasErrors(['email' => 'That isn\'t an email address, like name@gmail.com.']);
    }

    public function test_links_are_limited_per_email_and_per_address(): void
    {
        foreach (range(1, 3) as $time) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => 'someone@gmail.com'])->assertSessionHasNoErrors();
        }
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'someone@gmail.com'])
            ->assertSessionHasErrors(['email' => 'Too many links asked for. Try again in 15 minutes.']);

        // Another email from the same place is fine, up to 20 in 15 minutes.
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'other@gmail.com'])->assertSessionHasNoErrors();
    }

    public function test_the_link_leads_to_a_page_to_choose_a_new_password_typed_twice(): void
    {
        $token = $this->askForLink();

        $this->get("/reset-password/{$token}?email=ada%40gmail.com")->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('<h1>Choose a new password</h1>', false)
            ->assertSee('<input type="hidden" name="token" value="'.$token.'" />', false)
            ->assertSeeInOrder(['<label for="password">New password</label>', 'autocomplete="new-password"', '<label for="password_confirmation">Type it again</label>'], false);

        $save = fn (array $fields) => $this->from("/reset-password/{$token}?email=ada%40gmail.com")
            ->post('/reset-password', $fields + ['token' => $token, 'email' => 'ada@gmail.com']);

        // Typed differently the second time, too short, or the password it has now: nothing changes, and the link
        // still works.
        $save(['password' => 'second-password', 'password_confirmation' => 'second-passwort'])
            ->assertSessionHasErrors(['password' => 'The two passwords are different. Type the same new password in both.']);
        $save(['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $save(['password' => 'first-password', 'password_confirmation' => 'first-password'])
            ->assertSessionHasErrors(['password' => 'That\'s the password your account has now. Choose a different one.']);
        $this->assertTrue(Hash::check('first-password', $this->ada->fresh()->Password));

        // A new one: saved, the link used up, and an email to say so.
        $save(['password' => 'second-password', 'password_confirmation' => 'second-password'])
            ->assertRedirect('/login')
            ->assertSessionHas('message', 'Your password has been changed. Log in with it now.');
        $this->assertTrue(Hash::check('second-password', $this->ada->fresh()->Password));
        Notification::assertSentTo($this->ada, PasswordChanged::class, function (PasswordChanged $changed) {
            $this->assertSame('Your '.config('app.name').' password was changed', $changed->toMail($this->ada)->subject);

            return true;
        });

        $this->post('/login', ['login' => 'ada.l', 'password' => 'first-password'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'ada.l', 'password' => 'second-password'])->assertRedirect('/dashboard');
        $this->post('/logout');

        // The link works once.
        $this->get("/reset-password/{$token}?email=ada%40gmail.com")->assertSee("<h1>This link doesn't work</h1>", false)
            ->assertSee('href="'.route('password.request').'">Ask for a new link</a>', false);
        $save(['password' => 'third-password', 'password_confirmation' => 'third-password'])
            ->assertSessionHasErrors(['email' => 'This link has run out or was used already. Ask for a new one below.']);
        $this->assertTrue(Hash::check('second-password', $this->ada->fresh()->Password));
    }

    public function test_a_link_works_for_60_minutes(): void
    {
        $token = $this->askForLink();

        $this->travel(61)->minutes();

        $this->get("/reset-password/{$token}?email=ada%40gmail.com")->assertSee("This link doesn't work", false);
        $this->post('/reset-password', ['token' => $token, 'email' => 'ada@gmail.com', 'password' => 'second-password', 'password_confirmation' => 'second-password'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('first-password', $this->ada->fresh()->Password));
    }

    public function test_an_account_whose_password_an_administrator_took_away_stays_locked(): void
    {
        $token = $this->askForLink();
        $this->ada->forceFill(['Password' => null])->save();

        $this->get("/reset-password/{$token}?email=ada%40gmail.com")->assertSee("This link doesn't work", false);
        $this->post('/reset-password', ['token' => $token, 'email' => 'ada@gmail.com', 'password' => 'second-password', 'password_confirmation' => 'second-password'])
            ->assertSessionHasErrors('email');
        $this->assertNull($this->ada->fresh()->Password);
    }

    public function test_a_new_password_logs_the_account_out_everywhere_else(): void
    {
        // Logged in here, then the password is changed elsewhere, as by "Forgot password?" on another device.
        $this->post('/login', ['login' => 'ada.l', 'password' => 'first-password'])->assertRedirect('/dashboard');
        $this->get('/profile')->assertOk();

        $this->ada->forceFill(['Password' => 'second-password'])->save();

        // The next page loads the account afresh, as every request does outside a test.
        $this->app['auth']->forgetGuards();
        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }
}
