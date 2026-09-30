<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Showing, uploading and removing another user's photo from the Edit user page, as routes on the Routes page:
     * [path, method, function]. Everyone's own photo is on My profile, which stays in the code.
     */
    private const Routes = [
        ['users/photo', 'GET', 'show'],
        ['users/store-photo', 'POST', 'store'],
        ['users/destroy-photo', 'DELETE', 'destroy'],
    ];

    /**
     * Add them under the Users menu link, open to whoever can open Edit user (ADMIN, unless that was changed).
     */
    public function up(): void
    {
        $edit = DB::table('AppRoutes')->where('Path', 'users/edit')->first();
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);
        $roleIds = $edit === null
            ? [$admin]
            : DB::table('AppRouteRoles')->where('AppRouteId', $edit->Id)->pluck('RoleId')->all();

        foreach (self::Routes as [$path, $method, $action]) {
            if (DB::table('AppRoutes')->where(['Path' => $path, 'HttpMethods' => $method, 'Parameters' => 'user'])->exists()) {
                continue;
            }

            $id = DB::table('AppRoutes')->insertGetId([
                'MenuItemId' => $edit->MenuItemId ?? DB::table('AppRoutes')->where('Path', 'users')->value('MenuItemId'),
                'Path' => $path,
                'Parameters' => 'user',
                'Controller' => 'Setup\UserPhotoController',
                'Action' => $action,
                'HttpMethods' => $method,
                'OpenToEveryone' => $edit === null ? false : (bool) $edit->OpenToEveryone,
            ]);

            if ($edit === null || ! $edit->OpenToEveryone) {
                DB::table('AppRouteRoles')->insert(array_map(fn ($roleId) => ['AppRouteId' => $id, 'RoleId' => $roleId], $roleIds));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::Routes as [$path, $method, $action]) {
            DB::table('AppRoutes')->where(['Path' => $path, 'HttpMethods' => $method, 'Controller' => 'Setup\UserPhotoController', 'Action' => $action])->delete();
        }
    }
};
