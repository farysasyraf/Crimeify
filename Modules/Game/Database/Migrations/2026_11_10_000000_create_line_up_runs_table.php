<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Line-Up's games: who played, the score, each round's state and suspects, and each order given. The server keeps
     * the rounds, so the suspects' counts never reach the browser before its order does.
     */
    public function up(): void
    {
        Schema::create('LineUpRuns', function (Blueprint $table) {
            $table->increments('Id');
            // What the browser sends with each order to say which game it's for.
            $table->string('Token', 64)->unique();
            // The name as the player typed it, and in lowercase, so each player's games count together.
            $table->string('PlayerName', 40);
            $table->string('PlayerKey', 40);
            $table->integer('Score')->default(0);
            // The game's rounds, dealt as it starts: each state, crime, year and its suspects with their counts.
            $table->text('Dealt');
            // Each round's order so far, and its points.
            $table->text('Orders');
            // When the round being ordered was shown, to time the order; none between an order and the next round.
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
        Schema::dropIfExists('LineUpRuns');
    }
};
