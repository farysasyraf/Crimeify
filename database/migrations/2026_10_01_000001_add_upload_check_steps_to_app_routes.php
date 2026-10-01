<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The steps an uploaded Excel file is checked in, shown as they go (upload-progress.js), on the Crime data and
     * Police stations pages: comparing the file's rows once it's been read, and the review page every check ends on, at
     * an address of its own. As routes on the Routes page, by page: [controller, [path, parameter, method, function]].
     */
    private const Routes = [
        'crime-data' => ['Map\CrimeDataController', [
            ['crime-data/compare', 'upload', 'POST', 'compare'],
            ['crime-data/review', 'upload', 'GET', 'review'],
        ]],
        'police-stations' => ['Map\PoliceStationController', [
            ['police-stations/compare', 'upload', 'POST', 'compare'],
            ['police-stations/review', 'upload', 'GET', 'review'],
        ]],
    ];

    /**
     * Add them under each page's menu link, for whoever can open its list (ADMIN, unless that was changed on the
     * Routes page).
     */
    public function up(): void
    {
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);

        foreach (self::Routes as $page => [$controller, $routes]) {
            $list = DB::table('AppRoutes')->where('Path', $page)->whereNull('Parameters')->first();
            $roleIds = $list === null ? [$admin] : DB::table('AppRouteRoles')->where('AppRouteId', $list->Id)->pluck('RoleId')->all();
            $openToEveryone = $list !== null && (bool) $list->OpenToEveryone;

            foreach ($routes as [$path, $parameter, $method, $action]) {
                if (DB::table('AppRoutes')->where(['Path' => $path, 'Parameters' => $parameter, 'HttpMethods' => $method])->exists()) {
                    continue;
                }

                $id = DB::table('AppRoutes')->insertGetId([
                    'MenuItemId' => $list?->MenuItemId ?? DB::table('MenuItems')->where('Url', "/{$page}")->value('Id'),
                    'Path' => $path,
                    'Parameters' => $parameter,
                    'Controller' => $controller,
                    'Action' => $action,
                    'HttpMethods' => $method,
                    'OpenToEveryone' => $openToEveryone,
                ]);

                if (! $openToEveryone) {
                    DB::table('AppRouteRoles')->insert(array_map(fn ($roleId) => ['AppRouteId' => $id, 'RoleId' => $roleId], $roleIds ?: [$admin]));
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::Routes as [$controller, $routes]) {
            foreach ($routes as [$path, $parameter, $method, $action]) {
                DB::table('AppRoutes')->where(['Path' => $path, 'HttpMethods' => $method, 'Controller' => $controller, 'Action' => $action])->delete();
            }
        }
    }
};
