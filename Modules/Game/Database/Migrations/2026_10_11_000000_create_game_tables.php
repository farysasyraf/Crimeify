<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Higher or Lower's games and the winners chosen from them. The game is public, but only administrators see the
     * scores, on the Game scores page.
     */
    public function up(): void
    {
        // One game: who played it, their score so far, and the two cards in play. The server keeps the cards, so the
        // count to guess never reaches the browser before the guess does.
        Schema::create('GameRuns', function (Blueprint $table) {
            $table->increments('Id');
            // What the browser sends with each guess to say which game it's for.
            $table->string('Token', 64)->unique();
            // The name as the player typed it, and in lowercase, so each player's games count together.
            $table->string('PlayerName', 40);
            $table->string('PlayerKey', 40);
            $table->integer('Score')->default(0);
            // The card shown and the one to guess, as Cards::card() gives them.
            $table->text('Current');
            $table->text('Next');
            // When the card to guess was shown, to time the answer, and when the score last went up.
            $table->dateTime('AskedAt');
            $table->dateTime('ScoredAt')->nullable();
            $table->dateTime('StartedAt');
            // When a wrong or late answer ended it. Still empty for a game left before then; its score stands.
            $table->dateTime('EndedAt')->nullable();
            $table->index('StartedAt');
            $table->index(['PlayerKey', 'Score']);
        });

        // A player chosen as the winner on the Game scores page, with the game and the dates it was chosen from. The
        // name and score are kept as they were, so it still shows if the games are deleted.
        Schema::create('GameWinners', function (Blueprint $table) {
            $table->increments('Id');
            $table->unsignedInteger('GameRunId')->nullable();
            $table->string('PlayerName', 40);
            $table->integer('Score');
            $table->date('FromDate')->nullable();
            $table->date('ToDate')->nullable();
            // Who chose them. The name is kept as it was, so it still shows after the user is deleted.
            $table->unsignedInteger('ChosenById')->nullable();
            $table->string('ChosenByName', 100);
            $table->dateTime('ChosenAt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('GameWinners');
        Schema::dropIfExists('GameRuns');
    }
};
