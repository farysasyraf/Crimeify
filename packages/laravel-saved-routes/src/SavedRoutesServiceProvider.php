<?php

namespace Farysasyraf\SavedRoutes;

use Farysasyraf\SavedRoutes\Console\ClearCacheCommand;
use Farysasyraf\SavedRoutes\Http\Middleware\AuthorizeSavedRoute;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SavedRoutesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/saved-routes.php', 'saved-routes');

        $this->app->singleton(ControllerLocator::class, fn ($app) => new ControllerLocator($app['config']->get('saved-routes.controllers', [])));
        $this->app->singleton(RouteRegistrar::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/saved-routes.php' => config_path('saved-routes.php')], 'saved-routes-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'saved-routes-migrations');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/saved-routes')], 'saved-routes-views');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'saved-routes');

        // The package's table and admin page are for its own model; an app with a model of its own has both already.
        if (SavedRoutes::usesOwnModel()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            if (config('saved-routes.admin.enabled')) {
                $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
            }
        }

        $this->registerMiddleware();
        $this->defineGate();

        if ($this->app->runningInConsole()) {
            $this->commands([ClearCacheCommand::class]);
        }

        // Once every provider has booted, so after the app's routes files and every package's routes.
        $this->app->booted(function () {
            if (config('saved-routes.register') && ! $this->app->routesAreCached()) {
                SavedRoutes::register();
            }
        });
    }

    /**
     * The middleware that checks a saved route's roles, run before route model binding.
     */
    private function registerMiddleware(): void
    {
        $this->app['router']->aliasMiddleware(SavedRoutes::ROLES_MIDDLEWARE, AuthorizeSavedRoute::class);

        $prioritise = fn (HttpKernel $kernel) => method_exists($kernel, 'addToMiddlewarePriorityBefore')
            ? $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, AuthorizeSavedRoute::class)
            : null;

        if ($this->app->resolved(HttpKernel::class)) {
            $prioritise($this->app->make(HttpKernel::class));
        } else {
            $this->app->afterResolving(HttpKernel::class, $prioritise);
        }
    }

    /**
     * The admin page decides who can open every saved route, so no one can use it until the app says who can,
     * by defining the gate itself.
     */
    private function defineGate(): void
    {
        if (! Gate::has(SavedRoutes::GATE)) {
            Gate::define(SavedRoutes::GATE, fn () => false);
        }
    }
}
