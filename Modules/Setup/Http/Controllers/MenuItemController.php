<?php

namespace Modules\Setup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppRoute;
use App\Models\MenuItem;
use App\Models\Role;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MenuItemController extends Controller
{
    public function index(): View
    {
        $items = MenuItem::tree(MenuItem::with(['roles' => fn ($query) => $query->orderBy('Name')])->ordered()->get());

        return view('setup::menu-items.index', compact('items'));
    }

    public function create(Request $request): View
    {
        $item = new MenuItem([
            'SortOrder' => (int) MenuItem::max('SortOrder') + 1,
            'VisibleToEveryone' => true,
        ]);

        // "+ Sub-link" on the menu page opens this form with the parent already chosen.
        $parent = MenuItem::find($request->integer('parent'));

        if ($parent !== null && $parent->level() < MenuItem::MaxLevel) {
            $item->ParentId = $parent->Id;
        }

        return view('setup::menu-items.create', $this->formData($item));
    }

    public function store(Request $request): RedirectResponse
    {
        [$input, $roleIds] = $this->validated($request);

        $item = DB::transaction(function () use ($input, $roleIds) {
            $item = MenuItem::create($input);
            $item->roles()->sync($roleIds);

            return $item;
        });

        return redirect(page_url('menu-items'))->with('message', "Added {$item->Label} to the menu.");
    }

    public function edit(MenuItem $menuItem): View
    {
        return view('setup::menu-items.edit', $this->formData($menuItem));
    }

    public function update(Request $request, MenuItem $menuItem): RedirectResponse
    {
        [$input, $roleIds] = $this->validated($request, $menuItem);

        DB::transaction(function () use ($menuItem, $input, $roleIds) {
            $menuItem->update($input);
            $menuItem->roles()->sync($roleIds);
        });

        return redirect(page_url('menu-items'))->with('message', "Saved changes to {$menuItem->Label}.");
    }

    public function delete(MenuItem $menuItem): View
    {
        $item = $menuItem->load('children.children');
        $ids = collect([$item])->concat($item->children)->concat($item->children->flatMap->children)->pluck('Id');

        return view('setup::menu-items.delete', [
            'item' => $item,
            'routeCount' => AppRoute::whereIn('MenuItemId', $ids)->count(),
        ]);
    }

    public function destroy(MenuItem $menuItem): RedirectResponse
    {
        $childIds = $menuItem->children()->pluck('Id');
        $grandchildIds = MenuItem::whereIn('ParentId', $childIds)->pluck('Id');

        DB::transaction(function () use ($menuItem, $childIds, $grandchildIds) {
            // Deepest first: dbo.MenuItems won't delete a link while sub-links still point to it.
            MenuItem::whereIn('Id', $grandchildIds)->delete();
            MenuItem::whereIn('Id', $childIds)->delete();
            $menuItem->delete();
        });

        $removed = $childIds->count() + $grandchildIds->count();
        $withSubLinks = $removed > 0 ? " and its {$removed} ".Str::plural('sub-link', $removed) : '';

        return redirect(page_url('menu-items'))->with('message', "Removed {$menuItem->Label}{$withSubLinks} from the menu.");
    }

    /**
     * What the add/edit form needs: the pages it can link to, the roles, the links it can be put under,
     * and how deep it may go.
     *
     * @return array<string, mixed>
     */
    private function formData(MenuItem $item): array
    {
        $tree = MenuItem::tree(MenuItem::ordered()->get());
        $notItself = fn (MenuItem $option) => ! $option->is($item);

        $level2Items = $tree->flatMap(fn (MenuItem $parent) => $parent->children->each(
            fn (MenuItem $child) => $child->setRelation('parent', $parent)
        ));

        return [
            'item' => $item,
            'pages' => AppRoute::linkablePages(),
            'roles' => Role::orderBy('Name')->get(),
            'level1Items' => $tree->filter($notItself)->values(),
            'level2Items' => $level2Items->filter($notItself)->values(),
            'maxLevel' => MenuItem::MaxLevel - $item->depthBelow(),
        ];
    }

    /**
     * Validate the form and map it onto the MenuItems columns, plus the Ids of the roles that can see it.
     *
     * @return array{0: array{Label: string, Url: ?string, Icon: ?string, SortOrder: int, VisibleToEveryone: bool, ParentId: ?int}, 1: list<int>}
     */
    private function validated(Request $request, ?MenuItem $item = null): array
    {
        $level = $request->integer('level');
        // A link with sub-links can't move so deep that they'd end up below level 3.
        $maxLevel = MenuItem::MaxLevel - ($item?->depthBelow() ?? 0);
        // "Other address…" in the Link list means the address typed in the box under it.
        $typedAddress = $request->input('url') === 'other';
        // Only app paths and web addresses, so a link can never run script (javascript:...).
        $addressRules = ['string', 'max:255', 'regex:~^(/|https?://)~i'];

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            // A page from the list; blank makes a heading.
            'url' => $typedAddress ? ['nullable'] : ['nullable', ...$addressRules],
            'url_other' => $typedAddress ? ['required', ...$addressRules] : ['nullable'],
            // A Material icon name, like account_balance, as AMV's Icon Menu. Every level shows one.
            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'level' => ['required', 'integer', 'in:1,2,3', 'max:'.$maxLevel],
            'parent_for_level_2' => $level === 2 ? ['required', 'integer', $this->parentAtLevel(1, $item)] : ['nullable'],
            'parent_for_level_3' => $level === 3 ? ['required', 'integer', $this->parentAtLevel(2, $item)] : ['nullable'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'visible_to' => ['required', Rule::in(['everyone', 'roles'])],
            'roles' => ['nullable', 'array', 'required_if:visible_to,roles'],
            'roles.*' => ['integer', 'distinct', Rule::exists(Role::class, 'Id')],
        ], [
            'url.regex' => 'The link must be a path starting with / (like /users/create) or a full address starting with http:// or https://.',
            'url_other.required' => 'Type the address, or choose a page from the list.',
            'url_other.regex' => 'The address must be a path starting with / (like /users?search=ali) or a full address starting with http:// or https://.',
            'icon.regex' => 'Use a Material icon name: lowercase letters, numbers and _, like account_balance.',
            'level.max' => 'This link has sub-links under it, so it can be at most level :max.',
            'parent_for_level_2.required' => 'Choose the level 1 link to put it under.',
            'parent_for_level_3.required' => 'Choose the level 2 link to put it under.',
            'roles.required_if' => 'Tick at least one role, or choose "Everyone who is logged in".',
            'roles.*.exists' => 'One of the selected roles no longer exists. Reload the page and try again.',
        ], [
            'url' => 'link',
            'url_other' => 'address',
            'sort_order' => 'position',
            'visible_to' => 'who can see this link',
        ]);

        $visibleToEveryone = $data['visible_to'] === 'everyone';

        return [
            [
                'Label' => $data['label'],
                'Url' => $typedAddress ? $data['url_other'] : ($data['url'] ?? null),
                'Icon' => $data['icon'] ?? null,
                'SortOrder' => (int) $data['sort_order'],
                'VisibleToEveryone' => $visibleToEveryone,
                'ParentId' => match ($level) {
                    2 => (int) $data['parent_for_level_2'],
                    3 => (int) $data['parent_for_level_3'],
                    default => null,
                },
            ],
            // Roles only matter when the link is limited to them.
            $visibleToEveryone ? [] : array_map('intval', $data['roles']),
        ];
    }

    /**
     * A rule that the chosen parent exists, sits at the given level, and isn't the link itself.
     */
    private function parentAtLevel(int $level, ?MenuItem $item): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($level, $item) {
            $parent = MenuItem::find($value);

            if ($parent === null || $parent->is($item) || $parent->level() !== $level) {
                $fail("That isn't a level {$level} link any more. Reload the page and choose again.");
            }
        };
    }
}
