<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let menu links nest up to three levels, and let a link with no address act as a heading.
     */
    public function up(): void
    {
        Schema::table('MenuItems', function (Blueprint $table) {
            $table->string('Url', 255)->nullable()->change();

            // The link this one sits under; null for level 1. SQL Server doesn't allow a cascading
            // delete on a table that points to itself, so the app deletes sub-links first.
            $table->unsignedInteger('ParentId')->nullable();
            $table->index('ParentId');
            $table->foreign('ParentId')->references('Id')->on('MenuItems');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('MenuItems', function (Blueprint $table) {
            $table->dropForeign(['ParentId']);
            $table->dropIndex(['ParentId']);
            $table->dropColumn('ParentId');
            $table->string('Url', 255)->nullable(false)->change();
        });
    }
};
