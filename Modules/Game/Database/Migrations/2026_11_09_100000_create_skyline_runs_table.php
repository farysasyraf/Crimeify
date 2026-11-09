<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skyline's games: who played, the score, each round's state and towers, and each guess. The server keeps the
     * rounds, so the count of the tower to guess never reaches the browser before its guess does.
     */
    public function up(): void
    {
        Schema::create('SkylineRuns', function (Blueprint $table) {
            $table->increments('Id');
            // What the browser sends with each guess to say which game it's for.
            $table->string('Token', 64)->unique();
            // The name as the player typed it, and in lowercase, so each player's games count together.
            $table->string('PlayerName', 40);
            $table->string('PlayerKey', 40);
            $table->integer('Score')->default(0);
            // The game's rounds, dealt as it starts: each state, crime, year, the tower to guess and every tower.
            $table->text('Dealt');
            // Each round's guess so far: the height, how far off as a share, and its points.
            $table->text('Guesses');
            // When the round being guessed was shown, to time the guess; none between a guess and the next round.
            $table->dateTime('AskedAt')->nullable();
            // When the score last went up, so of the same score, whoever reached it first ranks higher.
            $table->dateTime('ScoredAt')->nullable();
            $table->dateTime('StartedAt');
            $table->dateTime('EndedAt')->nullable();
            $table->index('StartedAt');
            $table->index(['PlayerKey', 'Score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('SkylineRuns');
    }
};
