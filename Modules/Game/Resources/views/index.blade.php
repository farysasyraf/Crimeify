@extends('layouts.public')

{{-- The game's name stays in English in Bahasa Melayu too, as its title is drawn. --}}
@section('title', 'Higher or Lower')
@section('description', __('Which police district had more crime? A game with the Royal Malaysia Police\'s figures.'))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <script src="{{ versioned_asset('modules/game/game.js') }}" defer></script>
@endpush

{{-- Higher or Lower, for everyone without logging in: a police district's count of a crime in a year, then another
     card of the same crime with its count hidden, to guess whether it's higher or lower before the time runs out.
     game.js plays it, asking GameController@start and @guess, which keep the cards and the score; the cards come from
     <template>s here, so their words are in the page's language. Only administrators see the scores, on the Game
     scores page. --}}
@section('content')
<div class="game-page">
<div class="page-head">
    <div>
        @include('game::_title')
        <p class="muted">{{ __('Which police district had more crime? Guess whether the next count is higher or lower.') }}</p>
    </div>
    {{-- A live game, hosted on a big screen: its page asks for the code. --}}
    <a class="btn btn-outline game-live-link" href="{{ route('public.game.live') }}">
        <span class="material-icon" aria-hidden="true">groups</span>{{ __('Join a live game') }}
    </a>
</div>

@if (! $ready)
    <div class="empty">
        <p>{{ __('There are no crime figures to play with yet.') }}</p>
    </div>
@else
    <div class="game" data-game data-guess="{{ $guessUrl }}" data-seconds="{{ $seconds }}">
        <noscript>
            <div class="alert alert-info">{{ __('The game needs JavaScript. Turn it on in your browser to play.') }}</div>
        </noscript>

        {{-- Before a game: how to play, and the name to play as. --}}
        <section class="game-panel game-intro" data-game-intro aria-labelledby="game-rules-heading">
            <div class="game-rules">
                <h2 id="game-rules-heading">{{ __('How to play') }}</h2>
                <ol>
                    <li>{{ __("Each round has two cards, each a police district's count of the same crime in one year.") }}</li>
                    <li>{{ __("Guess whether the second card's count is higher or lower than the first's.") }}</li>
                    <li>{{ __('Each right guess scores a point. One wrong guess, or :seconds seconds without one, ends the game.', ['seconds' => $seconds]) }}</li>
                    <li>{{ __('The higher your score, the closer the counts get.') }}</li>
                </ol>
            </div>
            <form class="game-start" method="post" action="{{ $startUrl }}" data-game-start>
                @csrf
                <div class="field">
                    <label for="game-name">{{ __('Your name') }}</label>
                    <input type="text" id="game-name" name="name" maxlength="{{ $nameLength }}" autocomplete="name" required
                        aria-describedby="game-name-note" />
                </div>
                <p class="game-error" role="alert" data-game-error hidden></p>
                <button type="submit" class="btn btn-primary game-play">
                    <span class="material-icon" aria-hidden="true">play_arrow</span>{{ __('Start') }}
                </button>
                <p class="game-note muted" id="game-name-note">{{ __("Your name and score go to :app's administrators, who choose the winner. Only they see the scores.", ['app' => config('app.name')]) }}</p>
            </form>
        </section>

        {{-- During a game: the score, the time left, and the two cards. --}}
        <section class="game-board" data-game-board hidden aria-label="Higher or Lower">
            <div class="game-status">
                <p class="game-score">{{ __('Score') }} <strong data-game-score>0</strong></p>
                <div class="game-timer" aria-hidden="true"><span class="game-timer-bar" data-game-timer-bar></span></div>
                <p class="game-seconds"><span data-game-seconds>{{ $seconds }}</span> s</p>
            </div>
            <div class="game-cards" data-game-cards></div>
            <p class="game-keys muted">{{ __('Keys work too:') }} <kbd>↑</kbd> {{ __('higher') }}, <kbd>↓</kbd> {{ __('lower') }}</p>
            <p class="visually-hidden" aria-live="polite" data-game-said></p>
        </section>

        {{-- After a game. --}}
        <section class="game-panel game-over" data-game-over hidden aria-labelledby="game-over-heading">
            <span class="material-icon game-over-icon" aria-hidden="true">sports_score</span>
            <h2 id="game-over-heading" tabindex="-1" data-game-over-title>{{ __('Game over') }}</h2>
            <p class="game-over-why" data-game-over-why></p>
            <p class="game-final">{{ __('Your score') }} <strong data-game-final>0</strong></p>
            <div class="game-over-actions">
                <button type="button" class="btn btn-primary game-play" data-game-again>
                    <span class="material-icon" aria-hidden="true">replay</span>{{ __('Play again') }}
                </button>
                <button type="button" class="btn btn-ghost" data-game-rename>{{ __('Change name') }}</button>
            </div>
        </section>

        @include('game::_card')
    </div>
@endif
</div>
@endsection
