<?php

namespace Farysasyraf\SavedRoutes\Roles;

use Farysasyraf\SavedRoutes\Contracts\RoleProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Roles from a Role model of the app's own, set in saved-routes.roles: the model, the column shown as its name,
 * and the relation on the user model that gives a user's roles, like a belongsToMany through role_user.
 */
class EloquentRoleProvider implements RoleProvider
{
    public function roles(): array
    {
        $role = $this->role();

        return $role->newQuery()->orderBy($this->label())->pluck($this->label(), $role->getKeyName())->all();
    }

    public function userHasAny(Authenticatable $user, array $keys): bool
    {
        $relation = config('saved-routes.roles.relation', 'roles');

        if ($keys === [] || ! method_exists($user, $relation)) {
            return false;
        }

        return $user->{$relation}()->whereIn($this->role()->getQualifiedKeyName(), $keys)->exists();
    }

    private function role(): Model
    {
        $model = config('saved-routes.roles.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException('Set saved-routes.roles.model to your Role model to use EloquentRoleProvider.');
        }

        return new $model;
    }

    private function label(): string
    {
        return config('saved-routes.roles.label', 'name');
    }
}
