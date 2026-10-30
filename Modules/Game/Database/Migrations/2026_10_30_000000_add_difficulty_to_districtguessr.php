<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DistrictGuessr's two levels: Easy, where the map zooms into each district's state, and Hard, the whole of
     * Malaysia as before. Each game keeps its level, so Game scores can rank the two apart.
     */
    public function up(): void
    {
        // Every game so far was played on the whole map: Hard.
        Schema::table('GuessrRuns', function (Blueprint $table) {
            $table->string('Difficulty', 10)->default('hard');
        });

        // A live game's level, chosen by its host; for DistrictGuessr only.
        Schema::table('LiveGames', function (Blueprint $table) {
            $table->string('Difficulty', 10)->default('hard');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('LiveGames', function (Blueprint $table) {
            $table->dropColumn('Difficulty');
        });

        Schema::table('GuessrRuns', function (Blueprint $table) {
            $table->dropColumn('Difficulty');
        });
    }
};
