<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move the Manage menu link into dbo.MenuItems, so roles can decide who sees it like any other link.
     * It starts visible to everyone, as the built-in link was.
     */
    public function up(): void
    {
        DB::table('MenuItems')->insert([
            'Label' => 'Manage menu',
            'Url' => '/menu-items',
            'SortOrder' => (int) DB::table('MenuItems')->max('SortOrder') + 1,
            'VisibleToEveryone' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('MenuItems')->where('Label', 'Manage menu')->where('Url', '/menu-items')->delete();
    }
};
