{{-- A card, filled in by game.js, live.js and live-host.js: the one shown, with its count, or the one to guess,
     with the buttons for a player. --}}
<template data-game-card>
    <article class="game-card">
        <span class="material-icon game-card-icon" aria-hidden="true" data-card="icon"></span>
        <p class="game-card-type" data-card="type"></p>
        <h3 class="game-card-district" data-card="district"></h3>
        <p class="game-card-where"><span data-card="place"></span> · <span data-card="year"></span></p>
        <div class="game-card-foot">
            <p class="game-card-count" data-card="count" hidden><strong data-card="crimes"></strong> <span>{{ __('crimes') }}</span></p>
            <div class="game-guess" data-card="guess" hidden>
                <p class="game-guess-ask" data-card="ask"></p>
                <div class="game-guess-buttons">
                    <button type="button" class="btn game-higher" data-guess="higher">
                        <span class="material-icon" aria-hidden="true">arrow_upward</span>{{ __('Higher') }}
                    </button>
                    <button type="button" class="btn game-lower" data-guess="lower">
                        <span class="material-icon" aria-hidden="true">arrow_downward</span>{{ __('Lower') }}
                    </button>
                </div>
            </div>
        </div>
    </article>
</template>
