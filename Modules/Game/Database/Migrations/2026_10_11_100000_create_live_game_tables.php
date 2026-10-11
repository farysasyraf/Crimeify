<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live games of Higher or Lower: one hosted from the Game scores page, the players who joined it with its code, and
     * each one's answer in each round.
     */
    public function up(): void
    {
        Schema::create('LiveGames', function (Blueprint $table) {
            $table->increments('Id');
            // What players type to join: six digits, not shared with another game that can still be joined.
            $table->string('Code', 6);
            // Who hosts it. The name is kept as it was, so it still shows after the user is deleted.
            $table->unsignedInteger('HostId')->nullable();
            $table->string('HostName', 100);
            $table->smallInteger('Rounds');
            $table->smallInteger('Seconds');
            // Every round's two cards, dealt as the game is made: [[shown, next], …], as Cards::deal() gives them.
            $table->text('Cards');
            // "lobby", "playing" or "finished", and the round being played: none in the lobby.
            $table->string('Status', 10);
            $table->smallInteger('Round')->default(0);
            // When the round's cards can be answered from, and until, in milliseconds since 1970: speed points need the
            // milliseconds, which a datetime column doesn't keep on every database.
            $table->bigInteger('OpensAt')->nullable();
            $table->bigInteger('ClosesAt')->nullable();
            $table->dateTime('CreatedAt');
            $table->dateTime('FinishedAt')->nullable();
            $table->index('Code');
        });

        Schema::create('LivePlayers', function (Blueprint $table) {
            $table->increments('Id');
            $table->unsignedInteger('LiveGameId');
            // What the player's phone sends with each check and answer to say who it is.
            $table->string('Token', 48)->unique();
            // The name as typed, and in lowercase, so no two players in a game share one.
            $table->string('PlayerName', 40);
            $table->string('PlayerKey', 40);
            $table->integer('Score')->default(0);
            $table->dateTime('JoinedAt');
            $table->unique(['LiveGameId', 'PlayerKey']);
            $table->foreign('LiveGameId')->references('Id')->on('LiveGames')->cascadeOnDelete();
        });

        Schema::create('LiveAnswers', function (Blueprint $table) {
            $table->increments('Id');
            $table->unsignedInteger('LivePlayerId');
            $table->unsignedInteger('LiveGameId');
            $table->smallInteger('Round');
            // "higher" or "lower", whether it was right, its points, and how long after the cards it came.
            $table->string('Guess', 6);
            $table->boolean('Correct');
            $table->integer('Points');
            $table->integer('Milliseconds');
            $table->dateTime('AnsweredAt');
            // One answer a round each.
            $table->unique(['LivePlayerId', 'Round']);
            $table->index(['LiveGameId', 'Round']);
            $table->foreign('LivePlayerId')->references('Id')->on('LivePlayers')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('LiveAnswers');
        Schema::dropIfExists('LivePlayers');
        Schema::dropIfExists('LiveGames');
    }
};
