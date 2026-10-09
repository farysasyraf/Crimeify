<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

use function Illuminate\Support\defer;

// "Forgot password?" on the login page: someone types their account's email, and gets a link by email to a page
// where they choose a new password, typed twice. Laravel's password broker keeps the links (hashed, one per account,
// for 60 minutes, config('auth.passwords.users')). Whether an account has the email is never said, so the form can't be
// used to find out who has an account. Only accounts that can log in get a link: one without a password stays as an
// administrator left it.
class PasswordResetController extends Controller
{
    /**
     * Links asked for per email address, and per IP address (a campus network shares one), every DecayMinutes.
     */
    private const PerEmail = 3;

    private const PerAddress = 20;

    private const DecayMinutes = 15;

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Send the link, if an account that can log in has the email. The reply is the same either way, and the email is
     * sent after it, so how long the reply takes doesn't tell either.
     */
    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']], [
            'email.required' => 'Type the email of your account.',
            'email.email' => 'That isn\'t an email address, like name@gmail.com.',
        ]);
        $email = Str::lower(trim($data['email']));

        $limits = ["password-link:{$email}" => self::PerEmail, 'password-link:'.$request->ip() => self::PerAddress];
        foreach ($limits as $key => $most) {
            if (RateLimiter::tooManyAttempts($key, $most)) {
                throw ValidationException::withMessages([
                    'email' => 'Too many links asked for. Try again in '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minutes.',
                ]);
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, self::DecayMinutes * 60);
        }

        defer(function () use ($email) {
            $user = User::query()->whereRaw('LOWER(Email) = ?', [$email])->first();

            if ($user?->canLogIn()) {
                Password::broker()->sendResetLink(['Email' => $user->Email]);
            }
        });

        return back()->with('sent', $email);
    }

    /**
     * The page the emailed link opens: the new password, twice. A link that has run out or was used already says so
     * straight away, with a way to ask for another. Its address, with the link's token, isn't passed on to other sites.
     */
    public function edit(Request $request, string $token): Response
    {
        $email = (string) $request->query('email', '');
        $user = $email === '' ? null : User::query()->where('Email', $email)->first();
        $valid = $user?->canLogIn() && Password::tokenExists($user, $token);

        return response()
            ->view('auth.reset-password', ['token' => $token, 'email' => $email, 'valid' => $valid])
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Save the new password, if the link is still good and it isn't the password the account has now. Then the link
     * stops working, everywhere else the account was logged in is logged out (AuthenticateSession, as the password
     * changed), and an email says the password was changed.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8), 'max:255'],
        ], [
            'password.required' => 'Type a new password.',
            'password.confirmed' => 'The two passwords are different. Type the same new password in both.',
        ]);

        $changed = null;
        $status = Password::broker()->reset(
            ['Email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) use (&$changed) {
                // An account an administrator took the password from after the link was sent stays that way.
                if (! $user->canLogIn()) {
                    throw ValidationException::withMessages(['email' => $this->expired()]);
                }
                // Checked before the link is used up, so a different password can be chosen with it.
                if (Hash::check($password, $user->Password)) {
                    throw ValidationException::withMessages([
                        'password' => 'That\'s the password your account has now. Choose a different one.',
                    ]);
                }

                $user->forceFill(['Password' => $password])->save();
                event(new PasswordReset($user));
                $changed = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || $changed === null) {
            throw ValidationException::withMessages(['email' => $this->expired()]);
        }

        $at = now();
        defer(fn () => $changed->notify(new PasswordChanged($at)));

        return to_route('login')->with('message', 'Your password has been changed. Log in with it now.');
    }

    private function expired(): string
    {
        return 'This link has run out or was used already. Ask for a new one below.';
    }
}
