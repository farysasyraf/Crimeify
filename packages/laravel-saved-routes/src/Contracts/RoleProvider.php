<?php

namespace Farysasyraf\SavedRoutes\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where the roles a saved route can be limited to come from, and how to tell whether a user has one.
 * Set the class in saved-routes.roles.provider.
 */
interface RoleProvider
{
    /**
     * Every role, as key => name, in the order the admin page offers them.
     *
     * @return array<int|string, string>
     */
    public function roles(): array;

    /**
     * Whether the user has at least one of these roles, given by key.
     *
     * @param  list<int|string>  $keys
     */
    public function userHasAny(Authenticatable $user, array $keys): bool;
}
