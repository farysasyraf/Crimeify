<?php

namespace Modules\Setup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use Closure;
use Farysasyraf\SavedRoutes\Parameters;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use ReflectionClass;

// The Routes page, ported from the AMV route page (Modules/Setup RouteController and routeTreeview):
// the add/edit form on the left, the menu tree with each link's routes on the right.
class AppRouteController extends Controller
{
    public function index(Request $request): View
    {
        return $this->page($request, new AppRoute(['HttpMethods' => 'GET']));
    }

    public function store(Request $request): RedirectResponse
    {
        $route = new AppRoute;
        $roleIds = $this->fillFromForm($request, $route);
        $route->save();
        $route->roles()->sync($roleIds);

        // As in AMV, saving opens the new route for editing.
        return to_route('routes.edit', $route)->with('message', "Added {$route->label()}.");
    }

    public function edit(Request $request, AppRoute $appRoute): View
    {
        return $this->page($request, $appRoute);
    }

    public function update(Request $request, AppRoute $appRoute): RedirectResponse
    {
        $roleIds = $this->fillFromForm($request, $appRoute);
        $appRoute->save();
        $appRoute->roles()->sync($roleIds);

        return to_route('routes.edit', $appRoute)->with('message', "Saved changes to {$appRoute->label()}.");
    }

    /**
     * Asks before deleting when JavaScript is off; with it, the form's own confirmation box does.
     */
    public function delete(AppRoute $appRoute): View
    {
        return view('setup::app-routes.delete', ['route' => $appRoute->load('menuItem')]);
    }

    public function destroy(AppRoute $appRoute): RedirectResponse
    {
        $appRoute->delete();

        return to_route('routes.index')->with('message', "Deleted {$appRoute->label()}.");
    }

    /**
     * The Routes page with the form showing this route: a new one to add, or a saved one to edit.
     */
    private function page(Request $request, AppRoute $route): View
    {
        $search = $request->string('search')->trim()->value();
        $menuItems = MenuItem::ordered()->get();
        $tree = MenuItem::tree($menuItems);
        // Taken before the search trims the tree, so every menu link can still be chosen.
        $menuGroups = $this->menuGroups($tree);

        $routes = AppRoute::with('roles')->orderBy('Path')->orderBy('Parameters')->get();
        $routesByMenu = $routes->groupBy(fn (AppRoute $saved) => (int) $saved->MenuItemId);

        // Open the groups holding the route being edited; 0 is the "No menu" group.
        $menuById = $menuItems->keyBy('Id');
        $openMenuIds = $route->exists && $route->MenuItemId === null ? [0] : [];

        for ($menu = $menuById->get((int) $route->MenuItemId); $menu !== null; $menu = $menuById->get((int) $menu->ParentId)) {
            $openMenuIds[] = $menu->Id;
        }

        return view('setup::app-routes.index', [
            'route' => $route,
            'search' => $search,
            'menus' => $this->withRoutes($tree, $routesByMenu, $search),
            'withoutMenu' => $routesByMenu->get(0, collect())
                ->filter(fn (AppRoute $saved) => $search === '' || $saved->matches($search))
                ->values(),
            'menuGroups' => $menuGroups,
            'openMenuIds' => $openMenuIds,
            'highlight' => $this->highlighter($search),
            'totalCount' => $routes->count(),
            'controllers' => SavedRoutes::controllers()->options(),
            'routesCached' => app()->routesAreCached(),
            'roles' => Role::orderBy('Name')->get(),
            'openTo' => old('open_to', $route->isLimitedToRoles() ? 'roles' : 'everyone'),
            'selectedRoles' => array_map('intval', old('roles', $route->exists ? $route->roles->pluck('Id')->all() : [])),
        ]);
    }

    /**
     * The Menu list as AMV builds it: a group for each level 1 link, offering its level 2 links that have
     * nothing under them, and its level 3 links named with the link above, like "Monthly / June". Level 1 links
     * with nothing under them, like Dashboard, come first, in a group of their own.
     *
     * @param  Collection<int, MenuItem>  $tree
     * @return list<array{label: string, options: array<int, string>}>
     */
    private function menuGroups(Collection $tree): array
    {
        $groups = [];
        $topLinks = $tree->filter(fn (MenuItem $top) => $top->children->isEmpty())->pluck('Label', 'Id')->all();

        if ($topLinks !== []) {
            $groups[] = ['label' => 'Top of the menu', 'options' => $topLinks];
        }

        foreach ($tree as $top) {
            $options = [];

            foreach ($top->children as $second) {
                if ($second->children->isEmpty()) {
                    $options[$second->Id] = $second->Label;
                }

                foreach ($second->children as $third) {
                    $options[$third->Id] = $second->Label.' / '.$third->Label;
                }
            }

            if ($options !== []) {
                $groups[] = ['label' => $top->Label, 'options' => $options];
            }
        }

        return $groups;
    }

    /**
     * Give each menu link its routes. While searching, as in AMV, a link whose label matches keeps all its routes,
     * other links keep only the matching ones, and links with nothing left on or under them are left out.
     *
     * @param  Collection<int, MenuItem>  $items
     * @param  Collection<int, Collection<int, AppRoute>>  $routesByMenu
     * @return Collection<int, MenuItem>
     */
    private function withRoutes(Collection $items, Collection $routesByMenu, string $search, bool $aboveMatched = false): Collection
    {
        $kept = collect();

        foreach ($items as $item) {
            $showAll = $search === '' || $aboveMatched || Str::contains($item->Label, $search, ignoreCase: true);
            $routes = $routesByMenu->get($item->Id, collect());

            $item->setRelation('appRoutes', ($showAll ? $routes : $routes->filter(fn (AppRoute $saved) => $saved->matches($search)))->values());
            $item->setRelation('children', $this->withRoutes($item->children, $routesByMenu, $search, $showAll && $search !== ''));

            if ($showAll || $item->appRoutes->isNotEmpty() || $item->children->isNotEmpty()) {
                $kept->push($item);
            }
        }

        return $kept;
    }

    /**
     * Marks each place the search term appears in a piece of text, escaping the rest, as AMV's tree search does.
     *
     * @return Closure(string): HtmlString
     */
    private function highlighter(string $search): Closure
    {
        return function (string $text) use ($search): HtmlString {
            $parts = $search === '' ? false : preg_split('/('.preg_quote($search, '/').')/iu', $text, flags: PREG_SPLIT_DELIM_CAPTURE);

            // The split puts each match at an odd position.
            return new HtmlString(collect($parts ?: [$text])
                ->map(fn (string $part, int $i) => $i % 2 === 1 ? '<mark>'.e($part).'</mark>' : e($part))
                ->implode(''));
        };
    }

    /**
     * Validate the form and copy it onto the route, refusing an address, method or name that's already taken.
     * Returns the Ids of the roles that can open it: none when it's open to everyone.
     *
     * @return list<int>
     */
    private function fillFromForm(Request $request, AppRoute $route): array
    {
        $locator = SavedRoutes::controllers();
        $controllers = $locator->find((string) $request->input('controller'));
        $controller = count($controllers) === 1 ? $controllers[0] : null;
        $menuIds = collect($this->menuGroups(MenuItem::tree(MenuItem::ordered()->get())))
            ->map(fn (array $group) => array_keys($group['options']))
            ->flatten()
            ->all();

        $data = $request->validate([
            'menu_item_id' => ['required', 'integer', Rule::in($menuIds)],
            // The fixed part of the address, which is also the route's name. Parameters go in their own box.
            'path' => ['required', 'string', 'max:200', 'regex:#^/?[\w.~-]+(/[\w.~-]+)*/?$#'],
            'controller' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, Closure $fail) use ($controllers, $locator) {
                if ($controllers === []) {
                    $fail("There's no controller named {$value} in app/Http/Controllers or a module's Http/Controllers.");
                } elseif (count($controllers) > 1) {
                    $names = collect($controllers)->map(fn (ReflectionClass $found) => $locator->nameOf($found))->implode(' and ');
                    $fail("More than one controller is called {$value}: {$names}. Type the one you mean, with its module.");
                }
            }],
            'function' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($controller, $locator) {
                if ($controller !== null && $locator->findAction($controller, $value) === null) {
                    $fail("{$controller->getShortName()} has no public function named {$value}.");
                }
            }],
            'method' => ['required', Rule::in(AppRoute::Methods)],
            'parameter' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail) {
                if (Parameters::parse($value) === null) {
                    $fail('Write parameter names like id or id/kw: letters, numbers and _, each name once, with optional ones (ending in ?) last.');
                }
            }],
            // Who can open it: everyone who is logged in, or only users with the roles ticked. Left out, everyone.
            'open_to' => ['nullable', Rule::in(['everyone', 'roles'])],
            'roles' => ['nullable', 'array', 'required_if:open_to,roles'],
            'roles.*' => ['integer', 'distinct', Rule::exists(Role::class, 'Id')],
        ], [
            'menu_item_id.required' => 'Choose the menu this route belongs to.',
            'menu_item_id.in' => 'Choose a menu from the list. Routes go under a menu link with nothing under it.',
            'path.regex' => 'Use letters, numbers, - _ . and ~, with / between parts, like reports/monthly. Put parameters like id in Parameter.',
            'method.required' => 'Choose GET, POST, PUT, DELETE or ANY.',
            'roles.required_if' => 'Tick at least one role, or choose "Everyone who is logged in".',
            'roles.*.exists' => 'One of the selected roles no longer exists. Reload the page and try again.',
        ], [
            'menu_item_id' => 'menu',
            'path' => 'route name',
            'open_to' => 'who can open it',
        ]);

        $openToEveryone = ($data['open_to'] ?? 'everyone') === 'everyone';

        $route->fill([
            'MenuItemId' => (int) $data['menu_item_id'],
            'Path' => trim($data['path'], '/'),
            'Parameters' => filled($data['parameter'] ?? null) ? Parameters::parse($data['parameter']) : null,
            // Saved as the code spells them, with the module in front, like Setup\RoleController, so the route
            // still works on a server where file names are case-sensitive.
            'Controller' => $locator->nameOf($controller),
            'Action' => $locator->findAction($controller, $data['function'])->getName(),
            'HttpMethods' => $data['method'],
            'OpenToEveryone' => $openToEveryone,
        ]);

        if (($clash = $route->clash()) !== null) {
            throw ValidationException::withMessages(['path' => $clash]);
        }

        return $openToEveryone ? [] : array_map('intval', $data['roles']);
    }
}
