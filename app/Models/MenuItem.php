<?php

namespace App\Models;

use App\Models\Concerns\SetsCreatedAt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

// A link in the left navigation menu, stored in dbo.MenuItems. Links nest up to three levels.
#[Table(name: 'MenuItems', key: 'Id', timestamps: false)]
#[Fillable(['Label', 'Url', 'SortOrder', 'VisibleToEveryone', 'ParentId', 'Icon'])]
class MenuItem extends Model
{
    use SetsCreatedAt;

    /**
     * The deepest level a link can sit at.
     */
    public const MaxLevel = 3;

    /**
     * The Material icon a link shows when none is chosen, as AMV's modules do.
     */
    public const DefaultIcon = 'content_paste';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'SortOrder' => 'integer',
            'VisibleToEveryone' => 'boolean',
            'ParentId' => 'integer',
            'CreatedAt' => 'datetime',
        ];
    }

    /**
     * The link this one sits under.
     *
     * @return BelongsTo<MenuItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'ParentId');
    }

    /**
     * The links directly under this one, in menu order.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'ParentId')->orderBy('SortOrder')->orderBy('Id');
    }

    /**
     * The routes grouped under this link on the Routes page.
     *
     * @return HasMany<AppRoute, $this>
     */
    public function appRoutes(): HasMany
    {
        return $this->hasMany(AppRoute::class, 'MenuItemId');
    }

    /**
     * The roles that can see this link when it isn't visible to everyone,
     * linked through dbo.MenuItemRoles.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'MenuItemRoles', 'MenuItemId', 'RoleId');
    }

    /**
     * A link without an address is a heading that only groups the links under it.
     */
    public function isHeading(): bool
    {
        return blank($this->Url);
    }

    /**
     * The Material icon name the sidebar shows in front of the link, at any level.
     */
    public function iconOrDefault(): string
    {
        return filled($this->Icon) ? $this->Icon : self::DefaultIcon;
    }

    /**
     * How this link relates to the page being viewed, as an aria-current value:
     * 'page' when it is that page, 'true' for a page under it (Roles on /roles/create), otherwise 'false'.
     * The home link ("/") only matches itself, and a page with a menu link of its own, like Add user on
     * /users/create, is marked by that link alone rather than also by the one it's under (Users).
     */
    public function currentState(string $currentUrl, bool $pageHasItsOwnLink = false): string
    {
        if ($this->isHeading()) {
            return 'false';
        }

        $href = url($this->Url);

        return match (true) {
            $href === $currentUrl => 'page',
            $this->Url !== '/' && ! $pageHasItsOwnLink && str_starts_with($currentUrl, $href.'/') => 'true',
            default => 'false',
        };
    }

    /**
     * Whether the page being viewed is this link or one of the links under it,
     * so the sidebar keeps this group open.
     */
    public function leadsTo(string $currentUrl): bool
    {
        return $this->currentState($currentUrl) !== 'false'
            || $this->children->contains(fn (MenuItem $child) => $child->leadsTo($currentUrl));
    }

    /**
     * 1 for a top-level link, 2 for a link under one, 3 for a link under a level 2 link.
     */
    public function level(): int
    {
        return match (true) {
            $this->ParentId === null => 1,
            $this->parent?->ParentId === null => 2,
            default => 3,
        };
    }

    /**
     * How many levels of sub-links sit under this link: 0, 1 or 2.
     */
    public function depthBelow(): int
    {
        if (! $this->exists) {
            return 0;
        }

        $childIds = $this->children()->pluck('Id');

        if ($childIds->isEmpty()) {
            return 0;
        }

        return static::whereIn('ParentId', $childIds)->exists() ? 2 : 1;
    }

    /**
     * Arrange a flat list of links, already in menu order, into the menu tree:
     * the level 1 links, each with the links under it in `children`.
     * A link whose parent isn't in the list is left out, so hiding a link hides everything under it.
     *
     * @param  Collection<int, MenuItem>  $items
     * @param  bool  $dropEmptyHeadings  Leave out headings with nothing under them, as the sidebar does.
     * @return Collection<int, MenuItem>
     */
    public static function tree(Collection $items, bool $dropEmptyHeadings = false): Collection
    {
        $byParent = $items->groupBy(fn (MenuItem $item) => (int) $item->ParentId);

        $build = function (int $parentId) use (&$build, $byParent, $dropEmptyHeadings): Collection {
            return collect($byParent->get($parentId, []))
                ->each(fn (MenuItem $item) => $item->setRelation('children', $build($item->Id)))
                ->reject(fn (MenuItem $item) => $dropEmptyHeadings && $item->isHeading() && $item->children->isEmpty())
                ->values();
        };

        return $build(0);
    }

    /**
     * Order items the way they appear in the menu.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('SortOrder')->orderBy('Id');
    }

    /**
     * Only the links this user may see: those visible to everyone,
     * plus those limited to at least one of the user's roles.
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $roleIds = $user->roles->modelKeys();

        $query->where(fn (Builder $query) => $query
            ->where('VisibleToEveryone', true)
            ->orWhereHas('roles', fn (Builder $query) => $query->whereKey($roleIds)));
    }
}
