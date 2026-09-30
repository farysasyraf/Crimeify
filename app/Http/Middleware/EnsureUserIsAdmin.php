<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Lets through only users with the ADMIN role. The Routes page and its add, edit and delete have it in the code
// (Modules/Setup/Routes/web.php): that page decides who can open every other page, so it can't be opened up from
// itself.
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only users with the '.Role::Admin.' role can open this page.');

        return $next($request);
    }
}
