<?php

use Illuminate\Support\Facades\Route;
use Modules\Game\Http\Controllers\LivePlayController;

// The Game module's API routes, which the module's RouteServiceProvider loads under /api with the "api" middleware
// group: no session, so a phone checking a live game about every second doesn't write one to the database each time,
// and no cookies, so they take the language with ?lang=.

// Playing a live game on a phone, for everyone without logging in (LivePlayController): joining with the code, then
// checking where the game is up to and answering, as the player the X-Player and X-Player-Token headers say. Joining
// is limited by IP address and answering by player too (RouteServiceProvider::limits()), set for a room of players
// sharing one address; the checks read what LiveGames keeps rather than the database.
Route::prefix('game/live')->name('game.live.')->group(function () {
    Route::middleware('throttle:live-join')->post('join', [LivePlayController::class, 'join'])->name('join');
    Route::get('{game}', [LivePlayController::class, 'state'])->whereNumber('game')->name('state');
    Route::middleware('throttle:live-answer')->post('{game}/answer', [LivePlayController::class, 'answer'])->whereNumber('game')->name('answer');
});
