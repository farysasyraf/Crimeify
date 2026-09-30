<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let each menu link be shown to everyone or only to chosen roles.
     * Existing links stay visible to everyone.
     */
    public function up(): void
    {
        Schema::table('MenuItems', function (Blueprint $table) {
            $table->boolean('VisibleToEveryone')->default(true);
        });

        // The roles that can see a link when VisibleToEveryone is off.
        Schema::create('MenuItemRoles', function (Blueprint $table) {
            $table->unsignedInteger('MenuItemId');
            $table->unsignedInteger('RoleId');
            $table->primary(['MenuItemId', 'RoleId']);
            $table->index('RoleId');
            $table->foreign('MenuItemId')->references('Id')->on('MenuItems')->cascadeOnDelete();
            $table->foreign('RoleId')->references('Id')->on('Roles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('MenuItemRoles');

        Schema::table('MenuItems', function (Blueprint $table) {
            $table->dropColumn('VisibleToEveryone');
        });
    }
};
