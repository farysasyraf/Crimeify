<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a hashed password to dbo.Users. It's nullable: users without one can't log in.
     */
    public function up(): void
    {
        Schema::table('Users', function (Blueprint $table) {
            $table->string('Password', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Users', function (Blueprint $table) {
            $table->dropColumn('Password');
        });
    }
};
