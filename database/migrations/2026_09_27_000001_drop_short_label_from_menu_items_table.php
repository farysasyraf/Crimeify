<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Level 2 and 3 links now show a Material icon in the sidebar, like level 1 links,
     * so the short label they used to show instead is no longer needed.
     */
    public function up(): void
    {
        Schema::table('MenuItems', function (Blueprint $table) {
            $table->dropColumn('ShortLabel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('MenuItems', function (Blueprint $table) {
            $table->string('ShortLabel', 5)->nullable();
        });
    }
};
