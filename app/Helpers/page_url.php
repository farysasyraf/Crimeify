<?php

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Facades\Route;

if (! function_exists('page_url')) {
    /**
     * The address of a page saved on the Routes page, by its route name, which is its path, like page_url('users')
     * or page_url('crime-data', ['state' => 'Johor']). The name is looked up rather than assumed, because the Routes
     * page can rename or delete it: then this gives the path as it was, which shows "Not found", rather than an error.
     *
     * @param  array<string, mixed>  $parameters  the route's parameters, then any others as the query string
     */
    function page_url(string $name, array $parameters = []): string
    {
        if (Route::has($name)) {
            return route($name, $parameters);
        }

        // A record, like a User, stands for its key, as route() takes it.
        $parameters = array_map(fn ($value) => $value instanceof UrlRoutable ? $value->getRouteKey() : $value, $parameters);

        return url($name).($parameters === [] ? '' : '?'.http_build_query($parameters));
    }
}
