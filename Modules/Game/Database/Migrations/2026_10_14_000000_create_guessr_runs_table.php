<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DistrictGuessr's games, and which game each winner on the Game scores page was chosen from, now that there are two.
     */
    public function up(): void
    {
        // One game of DistrictGuessr: who played it, its score, its districts, and each guess. The server keeps the
        // districts, so where one is never reaches the browser before its guess does.
        Schema::create('GuessrRuns', function (Blueprint $table) {
            $table->increments('Id');
            // What the browser sends with each guess to say which game it's for.
            $table->string('Token', 64)->unique();
            // The name as the player typed it, and in lowercase, so each player's games count together.
            $table->string('PlayerName', 40);
            $table->string('PlayerKey', 40);
            $table->integer('Score')->default(0);
            // The game's districts, dealt as it starts: name, state, region, latitude and longitude each.
            $table->text('Districts');
            // Each round's guess so far: where, how far off, and its points.
            $table->text('Guesses');
            // When the district being guessed was shown, to time the guess; none between a guess and the next round.
            $table->dateTime('AskedAt')->nullable();
            // When the score last went up, so of the same score, whoever reached it first ranks higher.
            $table->dateTime('ScoredAt')->nullable();
            $table->dateTime('StartedAt');
            $table->dateTime('EndedAt')->nullable();
            $table->index('StartedAt');
            $table->index(['PlayerKey', 'Score']);
        });

        // Its game's key, as the Games page has it (Library). Those chosen so far were all from Higher or Lower.
        Schema::table('GameWinners', function (Blueprint $table) {
            $table->string('Game', 30)->default('higher-or-lower');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('GameWinners', function (Blueprint $table) {
            $table->dropColumn('Game');
        });

        Schema::dropIfExists('GuessrRuns');
    }
};
