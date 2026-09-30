<?php

use App\Http\Controllers\LoginController;
use App\Http\Middleware\EnsureUserCanOpenRoute;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\RecordLastSeen;
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
        // Someone already logged in who opens the login page goes to the page to start from instead.
        $middleware->redirectUsersTo(fn () => LoginController::home());

        // Someone not logged in who opens the site's address sees the public dashboard; any other page of the app
        // asks them to log in, as before.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('/') && Route::has('public.dashboard')
            ? route('public.dashboard')
            : route('login'));

        // A route saved on the Routes page for only some roles gets route-roles.
        // The Routes page is only for ADMIN, set in the code with admin, as it decides who can open everything else.
        $middleware->alias([
            'route-roles' => EnsureUserCanOpenRoute::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Both run before the record in the address is looked up, so someone without the role gets "Not allowed"
        // whether or not /users/edit/5 exists, rather than learning which records do.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureUserCanOpenRoute::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureUserIsAdmin::class);

        // When each user last opened a page, for the users log on the Users page.
        $middleware->web(append: RecordLastSeen::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
