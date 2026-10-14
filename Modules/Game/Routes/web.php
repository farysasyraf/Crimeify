<?php

use Illuminate\Support\Facades\Route;
use Modules\Game\Http\Controllers\GameController;
use Modules\Game\Http\Controllers\LibraryController;
use Modules\Game\Http\Controllers\LivePlayController;

// The Game scores page in the app (game-scores, GameScoreController@index) and its winner actions are routes saved on
// the Routes page, for ADMIN to start with, so who can see the scores is set there. The module's RouteServiceProvider
// loads this file inside the "web" middleware group.

// The games, for everyone without logging in: the Games page, and Higher or Lower with its start and guesses. They're
// here rather than on the Routes page, which adds its routes behind the login, so they're always public. Each visitor
// can load them 120 times a minute, far more than playing needs, even answering a card a second. They come in Bahasa
// Melayu or English (SetPublicLocale).
Route::middleware(['throttle:120,1', 'public-locale'])->group(function () {
    // The Games page, with every game, then Higher or Lower's own.
    Route::get('/public/game', [LibraryController::class, 'index'])->name('public.game');
    Route::get('/public/game/higher-or-lower', [GameController::class, 'publicIndex'])->name('public.game.higher-or-lower');
    Route::post('/public/game/start', [GameController::class, 'start'])->name('public.game.start');
    Route::post('/public/game/guess', [GameController::class, 'guess'])->name('public.game.guess');

    // A live game's page, to join with the code on the host's big screen and play on (LivePlayController). Joining,
    // the checks and the answers are in Routes/api.php.
    Route::get('/public/game/live', [LivePlayController::class, 'page'])->name('public.game.live');
});
