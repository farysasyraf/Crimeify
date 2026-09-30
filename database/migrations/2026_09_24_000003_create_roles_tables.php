<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create dbo.Roles and dbo.UserRoles, which links each user to any number of roles,
     * and add a Roles link to the left menu.
     */
    public function up(): void
    {
        Schema::create('Roles', function (Blueprint $table) {
            $table->increments('Id');
            $table->string('Name', 100)->unique();
            $table->string('Description', 255)->nullable();
            $table->dateTime('CreatedAt')->nullable()->useCurrent();
        });

        // Deleting a user or a role removes its links here automatically.
        Schema::create('UserRoles', function (Blueprint $table) {
            $table->unsignedInteger('UserId');
            $table->unsignedInteger('RoleId');
            $table->primary(['UserId', 'RoleId']);
            $table->index('RoleId');
            $table->foreign('UserId')->references('Id')->on('Users')->cascadeOnDelete();
            $table->foreign('RoleId')->references('Id')->on('Roles')->cascadeOnDelete();
        });

        DB::table('MenuItems')->insert([
            'Label' => 'Roles',
            'Url' => '/roles',
            'SortOrder' => (int) DB::table('MenuItems')->max('SortOrder') + 1,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('MenuItems')->where('Url', '/roles')->delete();

        Schema::dropIfExists('UserRoles');
        Schema::dropIfExists('Roles');
    }
};
