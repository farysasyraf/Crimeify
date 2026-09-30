<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create dbo.AppRoutes for the routes added on the Routes page, and add a Manage routes link to the left menu.
     */
    public function up(): void
    {
        Schema::create('AppRoutes', function (Blueprint $table) {
            $table->increments('Id');
            // The menu link the route is grouped under. Deleting that link keeps the route, just without a menu.
            $table->unsignedInteger('MenuItemId')->nullable();
            $table->string('Path', 200);
            $table->string('Parameters', 200)->nullable();
            $table->string('Controller', 150);
            $table->string('Action', 100);
            // GET, POST or ANY.
            $table->string('HttpMethods', 20);
            $table->dateTime('CreatedAt')->nullable()->useCurrent();
            $table->index('MenuItemId');
            $table->foreign('MenuItemId')->references('Id')->on('MenuItems')->nullOnDelete();
        });

        DB::table('MenuItems')->insert([
            'Label' => 'Manage routes',
            'Url' => '/routes',
            'SortOrder' => (int) DB::table('MenuItems')->max('SortOrder') + 1,
            'VisibleToEveryone' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('AppRoutes');

        DB::table('MenuItems')->where('Label', 'Manage routes')->where('Url', '/routes')->delete();
    }
};
