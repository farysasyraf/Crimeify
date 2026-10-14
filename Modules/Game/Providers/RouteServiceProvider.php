<?php

namespace Modules\Game\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Game';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();

        $this->limits();
    }

    /**
     * The games' own rate limits (config('game.limits')), apart from the public pages' throttle:120,1, which counts
     * every page from an IP address together: a room of colleagues on one Wi-Fi would share 120 a minute. Here what's
     * counted by address is set for a room, and each player is also counted on their own, by their game's token or, in
     * a live game, by who they are (X-Player).
     */
    private function limits(): void
    {
        RateLimiter::for('game-pages', fn (Request $request) => Limit::perMinute(config('game.limits.pages'))->by($request->ip()));

        // A solo game's start, by address; its guesses, by the game and by address.
        RateLimiter::for('game-play', function (Request $request) {
            $token = $request->input('token');

            return is_string($token) && $token !== ''
                ? [
                    Limit::perMinute(config('game.limits.guesses'))->by('game:'.sha1($token)),
                    Limit::perMinute(config('game.limits.room'))->by('room:'.$request->ip()),
                ]
                : Limit::perMinute(config('game.limits.starts'))->by('start:'.$request->ip());
        });

        RateLimiter::for('live-join', fn (Request $request) => Limit::perMinute(config('game.limits.joins'))->by($request->ip()));

        // A live game's answers, by the game and player, and by address.
        RateLimiter::for('live-answer', fn (Request $request) => [
            Limit::perMinute(config('game.limits.answers'))->by('player:'.$request->route('game').'/'.$request->header('X-Player')),
            Limit::perMinute(config('game.limits.room'))->by('room:'.$request->ip()),
        ]);
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/Routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     */
    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/Routes/api.php'));
    }
}
