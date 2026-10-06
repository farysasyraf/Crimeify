<?php

namespace Farysasyraf\SavedRoutes\Concerns;

use Farysasyraf\SavedRoutes\Parameters;
use Farysasyraf\SavedRoutes\RouteRegistrar;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What an Eloquent model that implements RouteRecord can tell about its route: its address, what handles it,
 * whether it opens as it is, whether its code is still there, and whether its address is taken. Saving or deleting
 * one drops the cached saved routes.
 *
 * @mixin Model
 */
trait ActsAsSavedRoute
{
    protected static function bootActsAsSavedRoute(): void
    {
        static::saved(fn () => SavedRoutes::forgetCache());
        static::deleted(fn () => SavedRoutes::forgetCache());
    }

    /**
     * The address as Laravel registers it: the path, then a placeholder for each parameter, like reports/{id}/{kw}.
     */
    public function uri(): string
    {
        return Parameters::uri($this->savedRoutePath(), $this->savedRouteParameters());
    }

    /**
     * The address as people see it, like /reports/{id}.
     */
    public function address(): string
    {
        return '/'.$this->uri();
    }

    /**
     * The method and address together, like GET /reports/{id}.
     */
    public function label(): string
    {
        return $this->savedRouteMethod().' '.$this->address();
    }

    /**
     * The controller function that handles it, like ReportController@monthly.
     */
    public function handler(): string
    {
        return $this->savedRouteController().'@'.$this->savedRouteAction();
    }

    /**
     * The HTTP verbs the route answers.
     *
     * @return list<string>
     */
    public function verbs(): array
    {
        return SavedRoutes::verbs($this->savedRouteMethod());
    }

    /**
     * Whether the address opens in a browser as it is: it answers GET and every parameter is optional.
     */
    public function canOpen(): bool
    {
        return in_array('GET', $this->verbs()) && Parameters::allOptional($this->savedRouteParameters());
    }

    /**
     * The full class name of the controller it points to.
     */
    public function controllerClass(): string
    {
        return SavedRoutes::controllers()->classFor($this->savedRouteController());
    }

    /**
     * Whether the controller or function it points to has gone from the code, so opening it fails.
     */
    public function isBroken(): bool
    {
        $controllers = SavedRoutes::controllers();
        $controller = $controllers->findOne($this->controllerClass());

        return $controller === null || $controllers->findAction($controller, $this->savedRouteAction()) === null;
    }

    /**
     * Whether the search term appears in the address or the controller function.
     */
    public function matches(string $term): bool
    {
        return Str::contains($this->address().' '.$this->handler(), $term, ignoreCase: true);
    }

    /**
     * Describe what already answers this route's address for its method, or already has its name, or return null
     * when neither is taken: the app's routes, then the other saved routes as the database has them now.
     */
    public function clash(): ?string
    {
        $others = static::query()->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))->get();

        return app(RouteRegistrar::class)->clash($this, $others);
    }
}
