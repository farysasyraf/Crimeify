<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Police stations page, where administrators keep the stations the Map page lists up to date, and each of
     * its actions, as routes on the Routes page: [path, parameter, method, function].
     */
    private const Routes = [
        ['police-stations', null, 'GET', 'index'],
        ['police-stations/create', null, 'GET', 'create'],
        ['police-stations/store', null, 'POST', 'store'],
        ['police-stations/edit', 'station', 'GET', 'edit'],
        ['police-stations/update', 'station', 'PUT', 'update'],
        ['police-stations/delete', 'station', 'GET', 'delete'],
        ['police-stations/destroy', 'station', 'DELETE', 'destroy'],
    ];

    /**
     * Add a Police stations menu link after Crime data, in the same group (Dev Module), shown only to ADMIN, and the
     * routes under it, for ADMIN only. Each can be opened to other roles on the Routes page.
     */
    public function up(): void
    {
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);

        $menuItemId = DB::table('MenuItems')->where('Url', '/police-stations')->value('Id');

        if ($menuItemId === null) {
            // In the group Crime data or the users list is in, if any; at the top level if not.
            $parentId = DB::table('MenuItems')->where('Url', '/crime-data')->value('ParentId')
                ?? DB::table('MenuItems')->where('Url', '/users')->value('ParentId');

            $menuItemId = DB::table('MenuItems')->insertGetId([
                'Label' => 'Police stations',
                'Url' => '/police-stations',
                'SortOrder' => (int) DB::table('MenuItems')->where('ParentId', $parentId)->max('SortOrder') + 1,
                'ParentId' => $parentId,
                'Icon' => 'local_police',
                'VisibleToEveryone' => false,
            ]);
            DB::table('MenuItemRoles')->insert(['MenuItemId' => $menuItemId, 'RoleId' => $admin]);
        }

        foreach (self::Routes as [$path, $parameter, $method, $action]) {
            $saved = DB::table('AppRoutes')->where('Path', $path)->where('HttpMethods', $method)
                ->when($parameter === null, fn ($query) => $query->whereNull('Parameters'), fn ($query) => $query->where('Parameters', $parameter))
                ->exists();

            if ($saved) {
                continue;
            }

            $id = DB::table('AppRoutes')->insertGetId([
                'MenuItemId' => $menuItemId,
                'Path' => $path,
                'Parameters' => $parameter,
                'Controller' => 'Map\PoliceStationController',
                'Action' => $action,
                'HttpMethods' => $method,
                'OpenToEveryone' => false,
            ]);
            DB::table('AppRouteRoles')->insert(['AppRouteId' => $id, 'RoleId' => $admin]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::Routes as [$path, $parameter, $method, $action]) {
            DB::table('AppRoutes')->where(['Path' => $path, 'HttpMethods' => $method, 'Controller' => 'Map\PoliceStationController', 'Action' => $action])->delete();
        }

        DB::table('MenuItems')->where('Url', '/police-stations')->delete();
    }
};
