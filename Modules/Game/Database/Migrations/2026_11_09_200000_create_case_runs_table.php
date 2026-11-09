<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Case File's games: who played, the score, each case's district, suspects and clues, the clues opened in the case
     * being played, and each pick. The server keeps the cases, so which suspect it is never reaches the browser before
     * its pick does.
     */
    public function up(): void
    {
        Schema::create('CaseRuns', function (Blueprint $table) {
            $table->increments('Id');
            // What the browser sends with each pick to say which game it's for.
            $table->string('Token', 64)->unique();
            // The name as the player typed it, and in lowercase, so each player's games count together.
            $table->string('PlayerName', 40);
            $table->string('PlayerKey', 40);
            $table->integer('Score')->default(0);
            // The game's cases, dealt as it starts.
            $table->text('Dealt');
            // The clues open in the case being played, the rap sheet to start with.
            $table->text('Opened');
            // Each case's pick so far: the suspect, whether it was them, the clues open and the points.
            $table->text('Picks');
            // When the case being played was shown, to time the pick; none between a pick and the next case.
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
        Schema::dropIfExists('CaseRuns');
    }
};
