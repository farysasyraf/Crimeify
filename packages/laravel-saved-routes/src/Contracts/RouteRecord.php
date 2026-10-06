<?php

namespace Farysasyraf\SavedRoutes\Contracts;

/**
 * A saved route as the package reads it, whatever table it's stored in. The package's own SavedRoute model
 * implements it; an app that keeps its routes in a table of its own implements it on that model, uses the
 * ActsAsSavedRoute trait, and sets saved-routes.model to it.
 */
interface RouteRecord
{
    /**
     * Every saved route, in the order they were added. Let a QueryException through when the table isn't there
     * yet: the package then adds none.
     *
     * @return iterable<static>
     */
    public static function savedRoutes(): iterable;

    /**
     * The route's key in its table.
     */
    public function savedRouteKey(): int|string;

    /**
     * The fixed part of the address, without slashes at the ends, like reports/monthly. It's also the route's name,
     * so code can link to it with route('reports/monthly').
     */
    public function savedRoutePath(): string;

    /**
     * The parameter names after the path, joined by /, like id/kw, with optional ones ending in ?. Null for none.
     */
    public function savedRouteParameters(): ?string;

    /**
     * The controller as ControllerLocator names it, like ReportController or Setup\RoleController.
     */
    public function savedRouteController(): string;

    /**
     * The controller's function that handles it.
     */
    public function savedRouteAction(): string;

    /**
     * The method it answers: one of SavedRoutes::METHODS.
     */
    public function savedRouteMethod(): string;

    /**
     * Who can open it: null for everyone who passes the saved routes' middleware, or the roles that can, as
     * key => name. An empty array opens it to no one, as when every role it was limited to has been deleted.
     *
     * @return ?array<int|string, string>
     */
    public function savedRouteRoles(): ?array;
}
