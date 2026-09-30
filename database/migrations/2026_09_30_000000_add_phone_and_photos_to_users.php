<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a phone number to dbo.Users, and dbo.UserPhotos for each user's profile photo, as bytes.
     * The photos have a table of their own so the logged-in user's row, read on every page, stays small.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('Users', 'Phone')) {
            Schema::table('Users', function (Blueprint $table) {
                $table->string('Phone', 30)->nullable();
            });
        }

        // Deleting a user deletes their photo.
        Schema::create('UserPhotos', function (Blueprint $table) {
            $table->unsignedInteger('UserId')->primary();
            $table->binary('Photo');
            $table->string('ContentType', 20);
            $table->dateTime('UpdatedAt', 3);
            $table->foreign('UserId')->references('Id')->on('Users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations. The photos go; phone numbers are kept, as the Users table's data always is.
     */
    public function down(): void
    {
        Schema::dropIfExists('UserPhotos');
    }
};
