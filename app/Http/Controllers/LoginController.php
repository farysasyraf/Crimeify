<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Failed attempts allowed per email or username and IP address before a one-minute lockout.
     */
    private const MaxAttempts = 5;

    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Log in with an email or a username, in the one box: with an @ it's an email, as a username never has one.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Type your email or username.',
        ]);

        $login = Str::lower($credentials['login']);
        $throttleKey = $login.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MaxAttempts)) {
            throw ValidationException::withMessages([
                'login' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        $account = str_contains($login, '@') ? ['Email' => $credentials['login']] : ['Username' => $login];

        if (! Auth::attempt($account + ['password' => $credentials['password']])) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'login' => 'The email, username or password is incorrect.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        // Back to the page asked for before logging in, unless that was only the site's address: then the dashboard.
        $intended = $request->session()->pull('url.intended');

        return redirect()->to($intended !== null && $intended !== url('/') ? $intended : static::home());
    }

    /**
     * The page to start from after logging in: the dashboard, or else the users list. Both are routes saved on the
     * Routes page, which may have been renamed or deleted, so failing both, pages that are always there: the Routes
     * page for ADMIN, to put them back, and My profile for everyone else.
     */
    public static function home(): string
    {
        return match (true) {
            Route::has('dashboard') => route('dashboard'),
            Route::has('users') => route('users'),
            (bool) auth()->user()?->isAdmin() => route('routes.index'),
            default => route('profile'),
        };
    }

    public function logout(Request $request): RedirectResponse
    {
        // So the users log shows them offline straight away, rather than after a few minutes.
        if ($request->user() instanceof User) {
            User::query()->whereKey($request->user()->Id)->update(['LoggedOutAt' => now()]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('message', "You've logged out.");
    }
}
