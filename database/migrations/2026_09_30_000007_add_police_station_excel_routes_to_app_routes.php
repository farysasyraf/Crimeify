<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Police stations page's Excel file, as the Crime data page has one: downloading it, uploading it back to see
     * what it would change, then applying or cancelling that. As routes on the Routes page: [path, parameter, method,
     * function].
     */
    private const Routes = [
        ['police-stations/download', null, 'GET', 'download'],
        ['police-stations/upload', null, 'POST', 'upload'],
        ['police-stations/apply', 'upload', 'POST', 'apply'],
        ['police-stations/cancel', 'upload', 'DELETE', 'cancel'],
    ];

    /**
     * Add them under the Police stations menu link, for whoever can open the page's list (ADMIN, unless that was
     * changed on the Routes page).
     */
    public function up(): void
    {
        $list = DB::table('AppRoutes')->where('Path', 'police-stations')->whereNull('Parameters')->first();
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);
        $roleIds = $list === null ? [$admin] : DB::table('AppRouteRoles')->where('AppRouteId', $list->Id)->pluck('RoleId')->all();
        $openToEveryone = $list !== null && (bool) $list->OpenToEveryone;

        foreach (self::Routes as [$path, $parameter, $method, $action]) {
            $saved = DB::table('AppRoutes')->where('Path', $path)->where('HttpMethods', $method)
                ->when($parameter === null, fn ($query) => $query->whereNull('Parameters'), fn ($query) => $query->where('Parameters', $parameter))
                ->exists();

            if ($saved) {
                continue;
            }

            $id = DB::table('AppRoutes')->insertGetId([
                'MenuItemId' => $list?->MenuItemId ?? DB::table('MenuItems')->where('Url', '/police-stations')->value('Id'),
                'Path' => $path,
                'Parameters' => $parameter,
                'Controller' => 'Map\PoliceStationController',
                'Action' => $action,
                'HttpMethods' => $method,
                'OpenToEveryone' => $openToEveryone,
            ]);

            if (! $openToEveryone) {
                DB::table('AppRouteRoles')->insert(array_map(fn ($roleId) => ['AppRouteId' => $id, 'RoleId' => $roleId], $roleIds ?: [$admin]));
            }
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
    }
};
