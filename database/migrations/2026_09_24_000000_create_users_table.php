<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create dbo.Users for a fresh database. MyAppDB already has it, so this does nothing there.
     */
    public function up(): void
    {
        if (Schema::hasTable('Users')) {
            return;
        }

        Schema::create('Users', function (Blueprint $table) {
            $table->increments('Id');
            $table->string('Name', 100);
            $table->string('Email', 255)->unique();
            $table->dateTime('CreatedAt')->nullable()->useCurrent();
        });
    }

    /**
     * Deliberately left empty: rolling back must never drop the existing Users table and its data.
     */
    public function down(): void
    {
        //
    }
};
