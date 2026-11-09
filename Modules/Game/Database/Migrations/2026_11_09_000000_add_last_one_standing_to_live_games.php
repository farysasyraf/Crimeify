<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live Higher or Lower's two modes: Classic, everyone plays every round for points, as before; and Last One
     * Standing, where a wrong answer, or none, puts a player out, so each player keeps the round they went out in.
     */
    public function up(): void
    {
        // Every live game so far was Classic.
        Schema::table('LiveGames', function (Blueprint $table) {
            $table->string('Mode', 20)->default('classic');
        });

        // The round a player went out in; none while they're still in, and 0 for one who joined after the game had
        // started, to watch.
        Schema::table('LivePlayers', function (Blueprint $table) {
            $table->integer('OutRound')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('LivePlayers', function (Blueprint $table) {
            $table->dropColumn('OutRound');
        });

        Schema::table('LiveGames', function (Blueprint $table) {
            $table->dropColumn('Mode');
        });
    }
};
