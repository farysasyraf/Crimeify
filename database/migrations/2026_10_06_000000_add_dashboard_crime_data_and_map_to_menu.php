<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Map page and the figures its pins load (data-crime): [path, function on Map\MapController].
     */
    private const MapRoutes = [
        ['map', 'index'],
        ['map/crime', 'crime'],
    ];

    /**
     * The menu links a new database was missing, which MyAppDB got by hand on Manage menu and Manage routes: the
     * Dashboard at the top, Crime data with the users list, and a Map Module heading with the Map page under it, with
     * the Map page's routes. The dashboard's and Crime data's routes were saved without a link (by
     * move_built_in_pages_to_app_routes, when there was none), so they go under the new ones.
     *
     * Anything already there is left as it is, so on a database that has them, like MyAppDB, this changes nothing.
     */
    public function up(): void
    {
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);

        // The Dashboard, first in the menu, for everyone who is logged in, as its route is.
        $dashboard = DB::table('MenuItems')->where('Url', '/dashboard')->value('Id')
            ?? $this->addLink('Dashboard', '/dashboard', null, 'dashboard', sortOrder: 0);

        // Crime data, in the group the users list is in, if any, for ADMIN only, as its routes are.
        $crimeData = DB::table('MenuItems')->where('Url', '/crime-data')->value('Id');
        if ($crimeData === null) {
            $crimeData = $this->addLink('Crime data', '/crime-data', DB::table('MenuItems')->where('Url', '/users')->value('ParentId'), 'table_chart', roleId: $admin);
        }

        // The Map page, under a Map Module heading at the end of the menu, for everyone who is logged in.
        $map = DB::table('MenuItems')->where('Url', '/map')->value('Id');
        if ($map === null) {
            $module = DB::table('MenuItems')->where('Label', 'Map Module')->whereNull('Url')->whereNull('ParentId')->value('Id')
                ?? $this->addLink('Map Module', null, null, 'map');
            $map = $this->addLink('Map', '/map', $module, 'place');
        }

        DB::table('AppRoutes')->whereNull('MenuItemId')->where('Path', 'dashboard')->update(['MenuItemId' => $dashboard]);
        DB::table('AppRoutes')->whereNull('MenuItemId')
            ->where(fn ($query) => $query->where('Path', 'crime-data')->orWhere('Path', 'like', 'crime-data/%'))
            ->update(['MenuItemId' => $crimeData]);

        // Open to everyone who is logged in, like the dashboard: the public map shows anyone the same figures.
        foreach (self::MapRoutes as [$path, $action]) {
            if (DB::table('AppRoutes')->where('Path', $path)->whereNull('Parameters')->whereIn('HttpMethods', ['GET', 'ANY'])->exists()) {
                continue;
            }

            DB::table('AppRoutes')->insert([
                'MenuItemId' => $map,
                'Path' => $path,
                'Parameters' => null,
                'Controller' => 'Map\MapController',
                'Action' => $action,
                'HttpMethods' => 'GET',
                'OpenToEveryone' => true,
                'CreatedAt' => now(),
            ]);
        }
    }

    /**
     * Add a menu link, after the others under the same parent unless given its place, shown to everyone unless given a
     * role. CreatedAt from the app, in Malaysia time, as SetsCreatedAt does: Azure SQL's own default is UTC.
     */
    private function addLink(string $label, ?string $url, ?int $parentId, string $icon, ?int $sortOrder = null, ?int $roleId = null): int
    {
        $id = DB::table('MenuItems')->insertGetId([
            'Label' => $label,
            'Url' => $url,
            'SortOrder' => $sortOrder ?? (int) DB::table('MenuItems')->where('ParentId', $parentId)->max('SortOrder') + 1,
            'ParentId' => $parentId,
            'Icon' => $icon,
            'VisibleToEveryone' => $roleId === null,
            'CreatedAt' => now(),
        ]);

        if ($roleId !== null) {
            DB::table('MenuItemRoles')->insert(['MenuItemId' => $id, 'RoleId' => $roleId]);
        }

        return $id;
    }

    /**
     * Reverse the migrations: the Map page's routes and links, and the Dashboard and Crime data links, whose routes
     * stay, under No menu, as they were. Like the other migrations that add pages, this takes them out even where they
     * were made by hand.
     */
    public function down(): void
    {
        foreach (self::MapRoutes as [$path, $action]) {
            DB::table('AppRoutes')->where(['Path' => $path, 'Controller' => 'Map\MapController', 'Action' => $action])->delete();
        }

        DB::table('MenuItems')->whereIn('Url', ['/map', '/dashboard', '/crime-data'])->delete();

        // The heading, once nothing is under it.
        $module = DB::table('MenuItems')->where('Label', 'Map Module')->whereNull('Url')->whereNull('ParentId')->value('Id');
        if ($module !== null && ! DB::table('MenuItems')->where('ParentId', $module)->exists()) {
            DB::table('MenuItems')->where('Id', $module)->delete();
        }
    }
};
