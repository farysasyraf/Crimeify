<?php

namespace Farysasyraf\SavedRoutes\Http\Controllers;

use Closure;
use Farysasyraf\SavedRoutes\Models\SavedRoute;
use Farysasyraf\SavedRoutes\Parameters;
use Farysasyraf\SavedRoutes\SavedRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use ReflectionClass;

/**
 * The admin page: the add/edit form beside the list of saved routes.
 */
class SavedRouteController extends Controller
{
    public function index(Request $request): View
    {
        $model = SavedRoutes::model();

        return $this->page($request, new $model(['method' => 'GET']));
    }

    public function store(Request $request): RedirectResponse
    {
        $model = SavedRoutes::model();
        $route = new $model;
        $this->fillFromForm($request, $route);
        $route->save();

        // Saving opens the new route for editing.
        return to_route('saved-routes.edit', $route)->with('saved-routes.message', "Added {$route->label()}.");
    }

    public function edit(Request $request, string $savedRoute): View
    {
        return $this->page($request, $this->find($savedRoute));
    }

    public function update(Request $request, string $savedRoute): RedirectResponse
    {
        $route = $this->find($savedRoute);
        $this->fillFromForm($request, $route);
        $route->save();

        return to_route('saved-routes.edit', $route)->with('saved-routes.message', "Saved changes to {$route->label()}.");
    }

    /**
     * Asks before deleting.
     */
    public function delete(string $savedRoute): View
    {
        return view('saved-routes::delete', ['route' => $this->find($savedRoute)]);
    }

    public function destroy(string $savedRoute): RedirectResponse
    {
        $route = $this->find($savedRoute);
        $route->delete();

        return to_route('saved-routes.index')->with('saved-routes.message', "Deleted {$route->label()}.");
    }

    /**
     * The route in the address. Looked up here rather than by route model binding, which would run before the
     * admin page's gate and tell someone not let in which routes exist.
     */
    private function find(string $key): SavedRoute
    {
        return SavedRoutes::model()::query()->whereKey($key)->firstOrFail();
    }

    /**
     * The page with the form showing this route: a new one to add, or a saved one to edit.
     */
    private function page(Request $request, SavedRoute $route): View
    {
        $search = $request->string('search')->trim()->value();
        $model = SavedRoutes::model();
        $saved = $model::query()->orderBy('path')->orderBy('parameters')->get();
        $roles = SavedRoutes::roles()?->roles();

        return view('saved-routes::index', [
            'route' => $route,
            'search' => $search,
            'routes' => $saved->filter(fn (SavedRoute $each) => $search === '' || $each->matches($search))->values(),
            'totalCount' => $saved->count(),
            'roleNames' => $roles ?? [],
            'rolesEnabled' => $roles !== null,
            'controllers' => SavedRoutes::controllers()->options(),
            'routesCached' => app()->routesAreCached(),
            'highlight' => $this->highlighter($search),
            'openTo' => old('open_to', $route->isLimitedToRoles() ? 'roles' : 'everyone'),
            'selectedRoles' => array_map('strval', old('roles', $route->roles ?? [])),
        ]);
    }

    /**
     * Marks each place the search term appears in a piece of text, escaping the rest.
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
     */
    private function fillFromForm(Request $request, SavedRoute $route): void
    {
        $locator = SavedRoutes::controllers();
        $controllers = $locator->find((string) $request->input('controller'));
        $controller = count($controllers) === 1 ? $controllers[0] : null;
        $roles = SavedRoutes::roles()?->roles();

        $data = $request->validate([
            // The fixed part of the address, which is also the route's name. Parameters go in their own box. No part
            // is only dots, which a browser would take as "this folder" or "the folder above".
            'path' => ['required', 'string', 'max:200', 'regex:#^/?(?!\.+(/|$))[\w.~-]+(/(?!\.+(/|$))[\w.~-]+)*/?$#'],
            'controller' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, Closure $fail) use ($controllers, $locator) {
                if ($controllers === []) {
                    $fail("There's no controller named {$value} in the controller folders.");
                } elseif (count($controllers) > 1) {
                    $names = collect($controllers)->map(fn (ReflectionClass $found) => $locator->nameOf($found))->implode(' and ');
                    $fail("More than one controller is called {$value}: {$names}. Type the one you mean, with its prefix.");
                }
            }],
            'function' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($controller, $locator) {
                if ($controller !== null && $locator->findAction($controller, $value) === null) {
                    $fail("{$controller->getShortName()} has no public function named {$value}.");
                }
            }],
            'method' => ['required', Rule::in(SavedRoutes::METHODS)],
            'parameter' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail) {
                if (Parameters::parse($value) === null) {
                    $fail('Write parameter names like id or id/kw: letters, numbers and _, each name once, with optional ones (ending in ?) last.');
                }
            }],
            // Who can open it: everyone who is logged in, or only users with the roles ticked. Left out, everyone.
            'open_to' => ['nullable', Rule::in($roles === null ? ['everyone'] : ['everyone', 'roles'])],
            'roles' => ['nullable', 'array', 'required_if:open_to,roles'],
            'roles.*' => ['distinct', Rule::in(array_map('strval', array_keys($roles ?? [])))],
        ], [
            'path.regex' => 'Use letters, numbers, - _ . and ~, with / between parts, like reports/monthly. Put parameters like id in Parameter.',
            'method.required' => 'Choose '.implode(', ', array_slice(SavedRoutes::METHODS, 0, -1)).' or ANY.',
            'roles.required_if' => 'Tick at least one role, or choose "Everyone who is logged in".',
            'roles.*.in' => 'One of the selected roles no longer exists. Reload the page and try again.',
        ], [
            'path' => 'route name',
            'open_to' => 'who can open it',
        ]);

        $limited = ($data['open_to'] ?? 'everyone') === 'roles';

        $route->fill([
            'path' => trim($data['path'], '/'),
            'parameters' => filled($data['parameter'] ?? null) ? Parameters::parse($data['parameter']) : null,
            // Saved as the code spells it, so the route still works on a server where file names are case-sensitive.
            'controller' => $locator->nameOf($controller),
            'action' => $locator->findAction($controller, $data['function'])->getName(),
            'method' => $data['method'],
            'roles' => $limited ? array_values(array_map('strval', $data['roles'])) : null,
        ]);

        if (($clash = $route->clash()) !== null) {
            throw ValidationException::withMessages(['path' => $clash]);
        }
    }
}
