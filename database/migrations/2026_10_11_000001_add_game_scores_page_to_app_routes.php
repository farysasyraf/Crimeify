<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Game scores page, where administrators see Higher or Lower's scores and choose the winner, and its actions,
     * as routes on the Routes page: [path, parameter, method, function on Game\GameScoreController].
     */
    private const Routes = [
        ['game-scores', null, 'GET', 'index'],
        ['game-scores/store-winner', null, 'POST', 'storeWinner'],
        ['game-scores/destroy-winner', 'winner', 'DELETE', 'destroyWinner'],
    ];

    /**
     * Add a Game scores menu link after the others in Crime data's group (Dev Module), shown only to ADMIN, and the
     * routes under it, for ADMIN only: the game is public, but its scores aren't. Each can be opened to other roles on
     * the Routes page.
     */
    public function up(): void
    {
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);

        $menuItemId = DB::table('MenuItems')->where('Url', '/game-scores')->value('Id');

        if ($menuItemId === null) {
            // In the group Crime data or the users list is in, if any; at the top level if not.
            $parentId = DB::table('MenuItems')->where('Url', '/crime-data')->value('ParentId')
                ?? DB::table('MenuItems')->where('Url', '/users')->value('ParentId');

            // CreatedAt from the app, in Malaysia time, as SetsCreatedAt does: Azure SQL's own default is UTC.
            $menuItemId = DB::table('MenuItems')->insertGetId([
                'Label' => 'Game scores',
                'Url' => '/game-scores',
                'SortOrder' => (int) DB::table('MenuItems')->where('ParentId', $parentId)->max('SortOrder') + 1,
                'ParentId' => $parentId,
                'Icon' => 'emoji_events',
                'VisibleToEveryone' => false,
                'CreatedAt' => now(),
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
                'Controller' => 'Game\GameScoreController',
                'Action' => $action,
                'HttpMethods' => $method,
                'OpenToEveryone' => false,
                'CreatedAt' => now(),
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
            DB::table('AppRoutes')->where(['Path' => $path, 'HttpMethods' => $method, 'Controller' => 'Game\GameScoreController', 'Action' => $action])->delete();
        }

        DB::table('MenuItems')->where('Url', '/game-scores')->delete();
    }
};
