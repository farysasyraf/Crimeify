<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The pages that were in the code, now routes on the Routes page, each under the menu link that opens it:
     * path => [controller, function, the address of its menu link, whether only ADMIN can open it].
     * The Routes page itself stays in the code, so it can't be deleted or broken from itself.
     */
    private const Pages = [
        'users' => ['Setup\UserController', 'index', '/users', false],
        'users/create' => ['Setup\UserController', 'create', '/users/create', false],
        'roles' => ['Setup\RoleController', 'index', '/roles', false],
        'roles/create' => ['Setup\RoleController', 'create', '/roles', false],
        'menu-items' => ['Setup\MenuItemController', 'index', '/menu-items', false],
        'menu-items/create' => ['Setup\MenuItemController', 'create', '/menu-items', false],
        'dashboard' => ['Dashboard\DashboardController', 'index', '/dashboard', false],
        'crime-data' => ['Map\CrimeDataController', 'index', '/crime-data', true],
        'crime-data/download' => ['Map\CrimeDataController', 'download', '/crime-data', true],
    ];

    /**
     * Add each page as a route, unless a route for its address is already saved. The users list moves from / to
     * /users, so menu links to / now point there; / itself takes the logged-in to the dashboard.
     */
    public function up(): void
    {
        DB::table('MenuItems')->where('Url', '/')->update(['Url' => '/users']);

        // The Crime data page is for ADMIN, as it was in the code.
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);

        foreach (self::Pages as $path => [$controller, $action, $menuUrl, $adminOnly]) {
            $saved = DB::table('AppRoutes')->where('Path', $path)->whereNull('Parameters')->whereIn('HttpMethods', ['GET', 'ANY'])->exists();

            if ($saved) {
                continue;
            }

            $id = DB::table('AppRoutes')->insertGetId([
                'MenuItemId' => DB::table('MenuItems')->where('Url', $menuUrl)->orderBy('Id')->value('Id'),
                'Path' => $path,
                'Parameters' => null,
                'Controller' => $controller,
                'Action' => $action,
                'HttpMethods' => 'GET',
                'OpenToEveryone' => ! $adminOnly,
            ]);

            if ($adminOnly) {
                DB::table('AppRouteRoles')->insert(['AppRouteId' => $id, 'RoleId' => $admin]);
            }
        }
    }

    /**
     * Reverse the migrations: remove the routes this added, once the pages are back in the code.
     */
    public function down(): void
    {
        foreach (self::Pages as $path => [$controller, $action]) {
            // The users list at /users may have been saved on the Routes page before this, so it stays.
            if ($path !== 'users') {
                DB::table('AppRoutes')->where(['Path' => $path, 'Controller' => $controller, 'Action' => $action])->delete();
            }
        }

        DB::table('MenuItems')->where('Url', '/users')->update(['Url' => '/']);
    }
};
