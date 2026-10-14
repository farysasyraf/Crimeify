<?php

use Illuminate\Support\Facades\Route;
use Modules\Game\Http\Controllers\DistrictGuessrController;
use Modules\Game\Http\Controllers\GameController;
use Modules\Game\Http\Controllers\LibraryController;
use Modules\Game\Http\Controllers\LivePlayController;

// The Game scores page in the app (game-scores, GameScoreController@index) and its winner actions are routes saved on
// the Routes page, for ADMIN to start with, so who can see the scores is set there. The module's RouteServiceProvider
// loads this file inside the "web" middleware group.

// The games, for everyone without logging in: the Games page, and each game with its start and guesses. They're
// here rather than on the Routes page, which adds its routes behind the login, so they're always public. They have
// their own rate limits (RouteServiceProvider::limits()), set for a room of colleagues sharing one IP address: the pages
// by address, a game's guesses by the game too. They come in Bahasa Melayu or English (SetPublicLocale).
Route::middleware('public-locale')->group(function () {
    Route::middleware('throttle:game-pages')->group(function () {
        // The Games page, with every game; each game's page; and a live game's, to join with the code on the host's
        // big screen and play on (LivePlayController), whose joining, checks and answers are in Routes/api.php.
        Route::get('/public/game', [LibraryController::class, 'index'])->name('public.game');
        Route::get('/public/game/higher-or-lower', [GameController::class, 'publicIndex'])->name('public.game.higher-or-lower');
        Route::get('/public/game/districtguessr', [DistrictGuessrController::class, 'page'])->name('public.game.districtguessr');
        Route::get('/public/game/live', [LivePlayController::class, 'page'])->name('public.game.live');
    });

    Route::middleware('throttle:game-play')->group(function () {
        // Higher or Lower's start and guesses.
        Route::post('/public/game/start', [GameController::class, 'start'])->name('public.game.start');
        Route::post('/public/game/guess', [GameController::class, 'guess'])->name('public.game.guess');

        // DistrictGuessr's start, guesses and next districts.
        Route::post('/public/game/districtguessr/start', [DistrictGuessrController::class, 'start'])->name('public.game.districtguessr.start');
        Route::post('/public/game/districtguessr/guess', [DistrictGuessrController::class, 'guess'])->name('public.game.districtguessr.guess');
        Route::post('/public/game/districtguessr/next', [DistrictGuessrController::class, 'next'])->name('public.game.districtguessr.next');
    });
});
