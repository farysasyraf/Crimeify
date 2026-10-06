<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's table for logins kept in the database (SESSION_DRIVER=database). A cloud host's disk is wiped each
     * time the app is deployed or restarted, which would log everyone out if logins were files, and a second copy of
     * the app wouldn't see the first one's files. On your own computer logins can stay files; the table is just
     * there. user_id isn't a foreign key: Laravel just records dbo.Users.Id in it.
     */
    public function up(): void
    {
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
