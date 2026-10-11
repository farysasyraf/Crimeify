<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hosting a live game of Higher or Lower, as routes on the Routes page: making one from the Game scores page, its
     * big screen, what the screen checks, and its buttons. [path, parameter, method, function on
     * Game\LiveGameController].
     */
    private const Routes = [
        ['game-scores/store-live', null, 'POST', 'store'],
        ['game-scores/live', 'liveGame', 'GET', 'host'],
        ['game-scores/live-state', 'liveGame', 'GET', 'state'],
        ['game-scores/live-next', 'liveGame', 'POST', 'next'],
        ['game-scores/live-close', 'liveGame', 'POST', 'close'],
        ['game-scores/live-finish', 'liveGame', 'POST', 'finish'],
    ];

    /**
     * Add them under the Game scores link, for ADMIN only, as its own routes are. Each can be opened to other roles on
     * the Routes page. A route already there is left as it is.
     */
    public function up(): void
    {
        $admin = DB::table('Roles')->where('Name', 'ADMIN')->value('Id')
            ?? DB::table('Roles')->insertGetId(['Name' => 'ADMIN', 'Description' => 'Can edit the crime figures on the Crime data page.']);
        $menuItemId = DB::table('MenuItems')->where('Url', '/game-scores')->value('Id');

        foreach (self::Routes as [$path, $parameter, $method, $action]) {
            $saved = DB::table('AppRoutes')->where('Path', $path)->where('HttpMethods', $method)
                ->when($parameter === null, fn ($query) => $query->whereNull('Parameters'), fn ($query) => $query->where('Parameters', $parameter))
                ->exists();

            if ($saved) {
                continue;
            }

            // CreatedAt from the app, in Malaysia time, as SetsCreatedAt does: Azure SQL's own default is UTC.
            $id = DB::table('AppRoutes')->insertGetId([
                'MenuItemId' => $menuItemId,
                'Path' => $path,
                'Parameters' => $parameter,
                'Controller' => 'Game\LiveGameController',
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
            DB::table('AppRoutes')->where(['Path' => $path, 'HttpMethods' => $method, 'Controller' => 'Game\LiveGameController', 'Action' => $action])->delete();
        }
    }
};
