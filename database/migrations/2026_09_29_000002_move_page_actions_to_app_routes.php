<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every action of the pages already on the Routes page, as routes of their own there, so who can use each is set
     * there too: [path, parameter, method, controller, function, the page whose menu link it goes under].
     * The addresses follow AMV's routes, the action then the record, like users/edit/5, since a route's parameters go
     * at the end of its address.
     */
    private const Actions = [
        ['users/store', null, 'POST', 'Setup\UserController', 'store', 'users'],
        ['users/edit', 'user', 'GET', 'Setup\UserController', 'edit', 'users'],
        ['users/update', 'user', 'PUT', 'Setup\UserController', 'update', 'users'],
        ['users/delete', 'user', 'GET', 'Setup\UserController', 'delete', 'users'],
        ['users/destroy', 'user', 'DELETE', 'Setup\UserController', 'destroy', 'users'],
        ['roles/store', null, 'POST', 'Setup\RoleController', 'store', 'roles'],
        ['roles/edit', 'role', 'GET', 'Setup\RoleController', 'edit', 'roles'],
        ['roles/update', 'role', 'PUT', 'Setup\RoleController', 'update', 'roles'],
        ['roles/delete', 'role', 'GET', 'Setup\RoleController', 'delete', 'roles'],
        ['roles/destroy', 'role', 'DELETE', 'Setup\RoleController', 'destroy', 'roles'],
        ['menu-items/store', null, 'POST', 'Setup\MenuItemController', 'store', 'menu-items'],
        ['menu-items/edit', 'menu_item', 'GET', 'Setup\MenuItemController', 'edit', 'menu-items'],
        ['menu-items/update', 'menu_item', 'PUT', 'Setup\MenuItemController', 'update', 'menu-items'],
        ['menu-items/delete', 'menu_item', 'GET', 'Setup\MenuItemController', 'delete', 'menu-items'],
        ['menu-items/destroy', 'menu_item', 'DELETE', 'Setup\MenuItemController', 'destroy', 'menu-items'],
        ['crime-data/update', null, 'PUT', 'Map\CrimeDataController', 'update', 'crime-data'],
        ['crime-data/store', null, 'POST', 'Map\CrimeDataController', 'store', 'crime-data'],
        ['crime-data/upload', null, 'POST', 'Map\CrimeDataController', 'upload', 'crime-data'],
        ['crime-data/apply', 'upload', 'POST', 'Map\CrimeDataController', 'apply', 'crime-data'],
        ['crime-data/cancel', 'upload', 'DELETE', 'Map\CrimeDataController', 'cancel', 'crime-data'],
        ['crime-data/delete', 'crimeStat', 'GET', 'Map\CrimeDataController', 'delete', 'crime-data'],
        ['crime-data/destroy', 'crimeStat', 'DELETE', 'Map\CrimeDataController', 'destroy', 'crime-data'],
    ];

    /**
     * The Add pages, which start for ADMIN too, like the saving they lead to.
     */
    private const AddPages = ['users/create', 'roles/create', 'menu-items/create'];

    /**
     * Add each action, under its page's menu link. Adding, changing and deleting start for ADMIN only, as the Crime
     * data page already is; the lists stay as they are. Each can be opened up, or limited further, on the Routes page.
     */
    public function up(): void
    {
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);

        foreach (self::Actions as [$path, $parameter, $method, $controller, $action, $page]) {
            $saved = DB::table('AppRoutes')->where('Path', $path)->where('HttpMethods', $method)
                ->when($parameter === null, fn ($query) => $query->whereNull('Parameters'), fn ($query) => $query->where('Parameters', $parameter))
                ->exists();

            if ($saved) {
                continue;
            }

            $id = DB::table('AppRoutes')->insertGetId([
                'MenuItemId' => DB::table('AppRoutes')->where('Path', $page)->whereNull('Parameters')->value('MenuItemId'),
                'Path' => $path,
                'Parameters' => $parameter,
                'Controller' => $controller,
                'Action' => $action,
                'HttpMethods' => $method,
                'OpenToEveryone' => false,
            ]);
            DB::table('AppRouteRoles')->insert(['AppRouteId' => $id, 'RoleId' => $admin]);
        }

        // The Add pages, if they're still open to everyone as they were saved.
        foreach (DB::table('AppRoutes')->whereIn('Path', self::AddPages)->where('OpenToEveryone', true)->pluck('Id') as $id) {
            DB::table('AppRoutes')->where('Id', $id)->update(['OpenToEveryone' => false]);
            DB::table('AppRouteRoles')->insertOrIgnore(['AppRouteId' => $id, 'RoleId' => $admin]);
        }
    }

    /**
     * Reverse the migrations: remove the routes this added, once the actions are back in the code.
     */
    public function down(): void
    {
        foreach (self::Actions as [$path, $parameter, $method, $controller, $action]) {
            DB::table('AppRoutes')->where(['Path' => $path, 'HttpMethods' => $method, 'Controller' => $controller, 'Action' => $action])->delete();
        }
    }
};
