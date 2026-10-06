<?php

namespace Modules\Setup\Support;

use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Copies the menu (dbo.MenuItems), the routes (dbo.AppRoutes) and who can see and open each, from one database to
 * another, as a JSON file: `menu:export` on one, `menu:import` on the other. They're rows made on the Manage menu and
 * Manage routes pages, not code, so a new database, like the cloud host's, only has what the migrations add.
 *
 * Roles go by name, since each database numbers them its own way. Users and their roles aren't part of it.
 */
class MenuTransfer
{
    /**
     * What the file says it is, so menu:import can tell it from any other JSON file.
     */
    public const Format = 'crimeify-menu';

    public const Version = 1;

    /**
     * Everything to copy, as plain data. Links and routes keep their Id here only as a "key", so a link can name its
     * parent and a route its link within the file.
     *
     * @return array<string, mixed>
     */
    public static function export(): array
    {
        $items = MenuItem::with('roles:Id,Name')->orderBy('Id')->get();
        $routes = AppRoute::with('roles:Id,Name')->orderBy('Id')->get();

        return [
            'format' => self::Format,
            'version' => self::Version,
            'exported_at' => now()->toIso8601String(),
            'database' => DB::connection()->getDatabaseName(),
            'roles' => Role::orderBy('Id')->get()
                ->map(fn (Role $role) => ['name' => $role->Name, 'description' => $role->Description])->all(),
            'menu' => $items->map(fn (MenuItem $item) => [
                'key' => $item->Id,
                'parent' => $item->ParentId,
                'label' => $item->Label,
                'url' => $item->Url,
                'sort_order' => $item->SortOrder,
                'icon' => $item->Icon,
                'visible_to_everyone' => (bool) $item->VisibleToEveryone,
                'roles' => $item->roles->pluck('Name')->all(),
            ])->all(),
            'routes' => $routes->map(fn (AppRoute $route) => [
                'menu' => $route->MenuItemId,
                'path' => $route->Path,
                'parameters' => $route->Parameters,
                'controller' => $route->Controller,
                'action' => $route->Action,
                'method' => $route->HttpMethods,
                'open_to_everyone' => (bool) $route->OpenToEveryone,
                'roles' => $route->roles->pluck('Name')->all(),
            ])->all(),
        ];
    }

    /**
     * Replace this database's links and routes with the ones in $data, all at once or not at all. Roles the file
     * names that this database doesn't have yet are added; no role is changed or deleted, and neither are users.
     *
     * @param  array<string, mixed>  $data
     * @return array{menu: int, routes: int, roles_added: list<string>}
     */
    public static function import(array $data): array
    {
        self::check($data);

        return DB::transaction(function () use ($data) {
            $added = [];
            foreach ($data['roles'] as $role) {
                if (! Role::where('Name', $role['name'])->exists()) {
                    Role::create(['Name' => $role['name'], 'Description' => $role['description'] ?? null]);
                    $added[] = $role['name'];
                }
            }
            $roleIds = Role::pluck('Id', 'Name');

            // The roles' links first, then the routes and the links themselves. A link's parent is cleared first,
            // so no link is left pointing at one already deleted.
            DB::table('AppRouteRoles')->delete();
            DB::table('MenuItemRoles')->delete();
            DB::table('AppRoutes')->delete();
            DB::table('MenuItems')->update(['ParentId' => null]);
            DB::table('MenuItems')->delete();

            // Each link after its parent, so the parent's new Id is known.
            $newIds = [];
            $waiting = collect($data['menu']);
            while ($waiting->isNotEmpty()) {
                [$ready, $waiting] = $waiting->partition(fn ($item) => $item['parent'] === null || isset($newIds[$item['parent']]));
                if ($ready->isEmpty()) {
                    throw new InvalidArgumentException('Some menu links sit under links that are never added.');
                }

                foreach ($ready as $item) {
                    $link = MenuItem::create([
                        'Label' => $item['label'],
                        'Url' => $item['url'],
                        'SortOrder' => $item['sort_order'] ?? 0,
                        'Icon' => $item['icon'] ?? null,
                        'VisibleToEveryone' => $item['visible_to_everyone'],
                        'ParentId' => $item['parent'] === null ? null : $newIds[$item['parent']],
                    ]);
                    $link->roles()->attach(collect($item['roles'])->map(fn ($name) => $roleIds[$name])->all());
                    $newIds[$item['key']] = $link->Id;
                }
            }

            foreach ($data['routes'] as $route) {
                $saved = AppRoute::create([
                    'MenuItemId' => $route['menu'] === null ? null : $newIds[$route['menu']],
                    'Path' => $route['path'],
                    'Parameters' => $route['parameters'] ?? null,
                    'Controller' => $route['controller'],
                    'Action' => $route['action'],
                    'HttpMethods' => $route['method'],
                    'OpenToEveryone' => $route['open_to_everyone'],
                ]);
                $saved->roles()->attach(collect($route['roles'])->map(fn ($name) => $roleIds[$name])->all());
            }

            return ['menu' => count($newIds), 'routes' => count($data['routes']), 'roles_added' => $added];
        });
    }

    /**
     * Stop before changing anything if the file isn't one menu:export wrote, or doesn't hang together: a link whose
     * parent isn't in it (or that is its own ancestor), a route under a link that isn't, or a role it doesn't list.
     *
     * @param  array<string, mixed>  $data
     */
    public static function check(array $data): void
    {
        if (($data['format'] ?? null) !== self::Format || ($data['version'] ?? null) !== self::Version) {
            throw new InvalidArgumentException('This isn\'t a file from "php artisan menu:export".');
        }
        foreach (['roles', 'menu', 'routes'] as $part) {
            if (! is_array($data[$part] ?? null)) {
                throw new InvalidArgumentException("The file has no list of {$part}.");
            }
        }

        foreach ($data['roles'] as $role) {
            if (blank($role['name'] ?? null)) {
                throw new InvalidArgumentException('Each role in the file needs a name.');
            }
        }

        $roles = collect($data['roles'])->pluck('name')->merge(Role::pluck('Name'))->filter()->unique();
        $keys = collect($data['menu'])->pluck('key');
        if ($keys->contains(null) || $keys->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Each menu link in the file needs a key of its own.');
        }

        $parents = collect($data['menu'])->pluck('parent', 'key');
        foreach ($data['menu'] as $item) {
            if (blank($item['label'] ?? null) || ! array_key_exists('url', $item) || ! is_bool($item['visible_to_everyone'] ?? null) || ! is_array($item['roles'] ?? null)) {
                throw new InvalidArgumentException("Menu link {$item['key']} is missing its label, link, who it's for or its roles.");
            }

            // Up through its parents, at most as deep as the menu goes, to the top.
            $seen = [];
            for ($at = $item['key']; $parents[$at] !== null; $at = $parents[$at]) {
                if (! $parents->has($parents[$at])) {
                    throw new InvalidArgumentException("Menu link \"{$item['label']}\" sits under a link that isn't in the file.");
                }
                if (isset($seen[$at]) || count($seen) >= MenuItem::MaxLevel - 1) {
                    throw new InvalidArgumentException("Menu link \"{$item['label']}\" sits more than ".MenuItem::MaxLevel.' levels deep, or under itself.');
                }
                $seen[$at] = true;
            }

            self::checkRoles($item['roles'], $roles, "Menu link \"{$item['label']}\"");
        }

        foreach ($data['routes'] as $route) {
            $name = $route['path'] ?? '?';
            if (blank($route['path'] ?? null) || blank($route['controller'] ?? null) || blank($route['action'] ?? null)
                || ! in_array($route['method'] ?? null, AppRoute::Methods, true) || ! is_bool($route['open_to_everyone'] ?? null) || ! is_array($route['roles'] ?? null)) {
                throw new InvalidArgumentException("Route {$name} is missing its path, controller, function, method, who can open it or its roles.");
            }
            if (($route['menu'] ?? null) !== null && ! $keys->contains($route['menu'])) {
                throw new InvalidArgumentException("Route {$name} is under a menu link that isn't in the file.");
            }

            self::checkRoles($route['roles'], $roles, "Route {$name}");
        }
    }

    /**
     * @param  array<int, mixed>  $names
     * @param  \Illuminate\Support\Collection<int, string>  $known
     */
    private static function checkRoles(array $names, $known, string $what): void
    {
        foreach ($names as $name) {
            if (! $known->contains($name)) {
                throw new InvalidArgumentException("{$what} is for the role {$name}, which isn't in the file or this database.");
            }
        }
    }
}
