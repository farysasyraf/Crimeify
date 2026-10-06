<?php

use App\Http\Controllers\LoginController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\RecordLastSeen;
use App\Http\Middleware\SetPublicLocale;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a cloud host's load balancer (Azure App Service and the like), the browser's https address and its
        // real IP arrive in X-Forwarded-* headers. TRUSTED_PROXIES is "*" for a host that is only reachable through
        // its balancer, or a comma-separated list of the balancer's addresses. Left empty, as on your own computer,
        // no one is trusted, so a visitor can't pass off a made-up IP address to get around the rate limits.
        if (filled($proxies = env('TRUSTED_PROXIES'))) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Someone already logged in who opens the login page goes to the page to start from instead.
        $middleware->redirectUsersTo(fn () => LoginController::home());

        // Someone not logged in who opens the site's address sees the public dashboard; any other page of the app
        // asks them to log in, as before.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('/') && Route::has('public.dashboard')
            ? route('public.dashboard')
            : route('login'));

        // The Routes page is only for ADMIN, set in the code with admin, as it decides who can open everything else.
        // (A route saved on it for only some roles gets the saved-routes package's saved-route-roles.)
        // The public dashboard and map get public-locale, for Bahasa Melayu or English.
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'public-locale' => SetPublicLocale::class,
        ]);

        // It runs before the record in the address is looked up, as saved-route-roles does, so someone without the
        // role gets "Not allowed" whether or not /routes/5/edit exists, rather than learning which records do.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureUserIsAdmin::class);

        // A login made before the account's password changed, like elsewhere after "Forgot password?", ends then
        // (AuthenticateSession). Then when each user last opened a page, for the users log on the Users page.
        $middleware->web(append: [AuthenticateSession::class, RecordLastSeen::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
