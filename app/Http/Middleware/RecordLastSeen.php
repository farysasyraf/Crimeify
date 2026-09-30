<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Notes when a logged-in user opens a page (Users.LastSeenAt), for the users log on the Users page. At most once a
// minute, so it isn't a write on every request, but straight away after logging in again.
class RecordLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->due($user)) {
            $now = now();

            try {
                User::query()->whereKey($user->Id)->update(['LastSeenAt' => $now]);
                $user->setAttribute('LastSeenAt', $now)->syncOriginalAttribute('LastSeenAt');
            } catch (QueryException $e) {
                // Before the column's migration has run, pages still open.
                report($e);
            }
        }

        return $next($request);
    }

    private function due(User $user): bool
    {
        return $user->LastSeenAt === null
            || $user->LastSeenAt->lte(now()->subMinute())
            || ($user->LoggedOutAt !== null && $user->LoggedOutAt->gte($user->LastSeenAt));
    }
}
