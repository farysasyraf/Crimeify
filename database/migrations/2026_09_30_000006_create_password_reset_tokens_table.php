<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The links to choose a new password that "Forgot password?" emails (PasswordResetController): one per email at
     * a time, kept hashed, so the table alone can't be used to reset anyone's password. Laravel's password broker
     * reads and writes it (config('auth.passwords.users')), so its columns keep Laravel's names.
     */
    public function up(): void
    {
        Schema::create('PasswordResetTokens', function (Blueprint $table) {
            $table->string('email', 255)->primary();
            $table->string('token', 255);
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('PasswordResetTokens');
    }
};
