<?php

namespace Farysasyraf\SavedRoutes\Roles;

use Farysasyraf\SavedRoutes\Contracts\RoleProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Roles from spatie/laravel-permission: its roles by id, checked with hasAnyRole() on the user.
 */
class SpatieRoleProvider implements RoleProvider
{
    public function roles(): array
    {
        $model = config('permission.models.role');

        return $model::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function userHasAny(Authenticatable $user, array $keys): bool
    {
        if ($keys === [] || ! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        // Spatie reads a string as a role's name, so keys stored as text go back to ids.
        return $user->hasAnyRole(array_map(fn (int|string $key) => is_numeric($key) ? (int) $key : $key, $keys));
    }
}
