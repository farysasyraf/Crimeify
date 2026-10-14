<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live games of DistrictGuessr as well as Higher or Lower: which game each is, and an answer that's a pin on the map,
     * with how far off it was.
     */
    public function up(): void
    {
        // Its key on the Games page. Those made so far were all Higher or Lower.
        Schema::table('LiveGames', function (Blueprint $table) {
            $table->string('Game', 30)->default('higher-or-lower');
        });

        // A DistrictGuessr answer's pin and its kilometres from the district; its Guess is "pin".
        Schema::table('LiveAnswers', function (Blueprint $table) {
            $table->decimal('Lat', 8, 5)->nullable();
            $table->decimal('Lng', 8, 5)->nullable();
            $table->decimal('Km', 8, 1)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('LiveAnswers', function (Blueprint $table) {
            $table->dropColumn(['Lat', 'Lng', 'Km']);
        });

        Schema::table('LiveGames', function (Blueprint $table) {
            $table->dropColumn('Game');
        });
    }
};
