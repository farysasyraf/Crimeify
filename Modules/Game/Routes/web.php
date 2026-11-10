<?php

use Illuminate\Support\Facades\Route;
use Modules\Game\Http\Controllers\CaseFileController;
use Modules\Game\Http\Controllers\DistrictGuessrController;
use Modules\Game\Http\Controllers\GameController;
use Modules\Game\Http\Controllers\LibraryController;
use Modules\Game\Http\Controllers\LineUpController;
use Modules\Game\Http\Controllers\LivePlayController;
use Modules\Game\Http\Controllers\SkylineController;

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
        Route::get('/public/game/skyline', [SkylineController::class, 'page'])->name('public.game.skyline');
        Route::get('/public/game/case-file', [CaseFileController::class, 'page'])->name('public.game.case-file');
        Route::get('/public/game/line-up', [LineUpController::class, 'page'])->name('public.game.line-up');
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

        // Skyline's start, guesses and next rounds.
        Route::post('/public/game/skyline/start', [SkylineController::class, 'start'])->name('public.game.skyline.start');
        Route::post('/public/game/skyline/guess', [SkylineController::class, 'guess'])->name('public.game.skyline.guess');
        Route::post('/public/game/skyline/next', [SkylineController::class, 'next'])->name('public.game.skyline.next');

        // Case File's start, clues, picks and next cases.
        Route::post('/public/game/case-file/start', [CaseFileController::class, 'start'])->name('public.game.case-file.start');
        Route::post('/public/game/case-file/clue', [CaseFileController::class, 'clue'])->name('public.game.case-file.clue');
        Route::post('/public/game/case-file/accuse', [CaseFileController::class, 'accuse'])->name('public.game.case-file.accuse');
        Route::post('/public/game/case-file/next', [CaseFileController::class, 'next'])->name('public.game.case-file.next');

        // Line-Up's start, orders and next rounds.
        Route::post('/public/game/line-up/start', [LineUpController::class, 'start'])->name('public.game.line-up.start');
        Route::post('/public/game/line-up/order', [LineUpController::class, 'order'])->name('public.game.line-up.order');
        Route::post('/public/game/line-up/next', [LineUpController::class, 'next'])->name('public.game.line-up.next');
    });
});
