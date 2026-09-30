<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let each route on the Routes page be opened by everyone who is logged in or only by chosen roles, as menu links
     * are shown. Existing routes stay open to everyone.
     */
    public function up(): void
    {
        Schema::table('AppRoutes', function (Blueprint $table) {
            $table->boolean('OpenToEveryone')->default(true);
        });

        // The roles that can open a route when OpenToEveryone is off.
        Schema::create('AppRouteRoles', function (Blueprint $table) {
            $table->unsignedInteger('AppRouteId');
            $table->unsignedInteger('RoleId');
            $table->primary(['AppRouteId', 'RoleId']);
            $table->index('RoleId');
            $table->foreign('AppRouteId')->references('Id')->on('AppRoutes')->cascadeOnDelete();
            $table->foreign('RoleId')->references('Id')->on('Roles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('AppRouteRoles');

        Schema::table('AppRoutes', function (Blueprint $table) {
            $table->dropColumn('OpenToEveryone');
        });
    }
};
