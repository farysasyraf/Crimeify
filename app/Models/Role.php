<?php

namespace App\Models;

use App\Models\Concerns\SetsCreatedAt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// A role that can be given to users, stored in dbo.Roles.
#[Table(name: 'Roles', key: 'Id', timestamps: false)]
#[Fillable(['Name', 'Description'])]
class Role extends Model
{
    use SetsCreatedAt;

    /**
     * The role that opens the Routes page, set in the code, as that page decides who can open every other page.
     * So there's always an ADMIN role with someone in it: it can't be deleted or renamed, and its last user can't
     * lose it or be deleted.
     */
    public const Admin = 'ADMIN';

    /**
     * Whether this is the ADMIN role.
     */
    public function isAdmin(): bool
    {
        return $this->getOriginal('Name', $this->Name) === self::Admin;
    }

    /**
     * How many users have the ADMIN role.
     */
    public static function adminCount(): int
    {
        return User::whereHas('roles', fn ($query) => $query->where('Name', self::Admin))->count();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'CreatedAt' => 'datetime',
            // From withCount(). SQL Server's driver returns counts as strings.
            'users_count' => 'integer',
            'menu_items_count' => 'integer',
        ];
    }

    /**
     * The menu links shown to this role, linked through dbo.MenuItemRoles.
     *
     * @return BelongsToMany<MenuItem, $this>
     */
    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'MenuItemRoles', 'RoleId', 'MenuItemId');
    }

    /**
     * The users who have this role, linked through dbo.UserRoles.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'UserRoles', 'RoleId', 'UserId');
    }
}
