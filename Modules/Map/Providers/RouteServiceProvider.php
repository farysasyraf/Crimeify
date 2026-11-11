<?php

namespace Modules\Map\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Map';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        parent::boot();

        // Ask Crimeify's questions and "Ask the map"'s requests, as every answer costs money (config('map.ask')): a
        // logged-in user's own, or for a visitor who isn't, fewer, by IP address, and all such visitors' together.
        RateLimiter::for('ask-crimeify', fn (Request $request) => $request->user() ? [
            Limit::perMinute(config('map.ask.per_minute'))->by('minute:'.$request->user()->getAuthIdentifier()),
            Limit::perDay(config('map.ask.per_day'))->by('day:'.$request->user()->getAuthIdentifier()),
        ] : [
            Limit::perMinute(config('map.ask.guest_per_minute'))->by('guest-minute:'.$request->ip()),
            Limit::perDay(config('map.ask.guest_per_day'))->by('guest-day:'.$request->ip()),
            Limit::perDay(config('map.ask.guests_per_day'))->by('guests-day'),
        ]);

        // "What am I looking at?" on the public map, by visitor, as anyone may ask it (config('map.ask')).
        RateLimiter::for('map-explain', fn (Request $request) => [
            Limit::perMinute(config('map.ask.explain_per_minute'))->by('minute:'.$request->ip()),
            Limit::perDay(config('map.ask.explain_per_day'))->by('day:'.$request->ip()),
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
