@extends('layouts.public')

{{-- The game's name, the same in Bahasa Melayu. --}}
@section('title', 'Skyline')
@section('description', __("A state's police districts as 3D towers, each as tall as its count of one crime. One is hidden: raise it to the height you think it is."))

@push('head')
    @include('game::_skyline-imports')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/districtguessr.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/skyline.css') }}" />
    <script src="{{ versioned_asset('modules/game/skyline-round.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/skyline.js') }}" defer></script>
@endpush

{{-- Skyline, for everyone without logging in: each round a state's police districts as 3D towers on its outline, each as
     tall as its count of one crime in one year, one of them a glass outline to raise to the height thought right, ten
     times. skyline.js plays it, asking SkylineController@start, @guess and @next, which keep the rounds and the score;
     skyline-round.js and skyline-scene.js draw each round. Only administrators see the scores, on the Game scores page. --}}
@section('content')
<div class="game-page">
<div class="page-head">
    <div>
        {{-- The title as drawn (Resources/title/make_title.py), its alt the game's name. --}}
        <h1 class="game-title">
            <img class="skyline-title" src="{{ versioned_asset('modules/game/title-skyline.svg') }}" alt="Skyline" lang="en" width="5775" height="952" />
        </h1>
        <p class="muted">{{ __("A state's police districts as 3D towers, each as tall as its count of one crime. One is hidden: raise it to the height you think it is.") }}</p>
    </div>
    <a class="btn btn-outline game-live-link" href="{{ route('public.game') }}">
        <span class="material-icon" aria-hidden="true">apps</span>{{ __('All games') }}
    </a>
</div>

@if (! $ready)
    <div class="empty">
        <p>{{ __('There are no crime figures to play with yet.') }}</p>
    </div>
@else
    <div class="game skyline" data-skyline data-start="{{ $urls['start'] }}" data-guess="{{ $urls['guess'] }}" data-next="{{ $urls['next'] }}"
        data-boundaries="{{ $boundaries }}" data-seconds="{{ $seconds }}" data-rounds="{{ $rounds }}" data-points="{{ $points }}"
        data-perfect="{{ $perfect }}">
        <noscript>
            <div class="alert alert-info">{{ __('The game needs JavaScript. Turn it on in your browser to play.') }}</div>
        </noscript>

        {{-- Before a game: how to play, and the name to play as. --}}
        <section class="game-panel game-intro" data-skyline-part="intro" aria-labelledby="skyline-rules-heading">
            <div class="game-rules">
                <h2 id="skyline-rules-heading">{{ __('How to play') }}</h2>
                <ol>
                    <li>{{ __("Each round shows a state's police districts as towers, each as tall as its count of one crime in one year.") }}</li>
                    <li>{{ __('One tower is a glass outline: raise it with the slider to how tall you think it is, then press Confirm. :seconds seconds a round.', ['seconds' => $seconds]) }}</li>
                    <li>{{ __('Up to :points points a round, all of them within :perfect%: the closer, the more.', ['points' => number_format($points), 'perfect' => $perfect]) }}</li>
                    <li>{{ __(':rounds rounds a game.', ['rounds' => $rounds]) }}</li>
                    <li>{{ __('Drag to turn the towers, pinch or scroll to zoom, and tap one for its count.') }}</li>
                </ol>
            </div>
            <form class="game-start" data-skyline-start novalidate>
                @csrf
                <div class="field">
                    <label for="skyline-name">{{ __('Your name') }}</label>
                    <input type="text" id="skyline-name" name="name" maxlength="{{ $nameLength }}" autocomplete="name" required aria-describedby="skyline-name-note" />
                </div>
                <p class="game-error" role="alert" data-skyline-error hidden></p>
                <button type="submit" class="btn btn-primary game-play">
                    <span class="material-icon" aria-hidden="true">play_arrow</span>{{ __('Start') }}
                </button>
                <p class="game-note muted" id="skyline-name-note">{{ __("Your name and score go to :app's administrators, who choose the winner. Only they see the scores.", ['app' => config('app.name')]) }}</p>
            </form>
        </section>

        {{-- A few seconds counting down before the first round. --}}
        <section class="game-panel game-ready" data-skyline-part="ready" hidden>
            <p class="muted">{{ __('Get ready…') }}</p>
            <p class="game-countdown" data-skyline="countdown" aria-hidden="true"></p>
        </section>

        {{-- A round: the time left, the towers and the guess; then how close it was, and its points. --}}
        <section class="skyline-board" data-skyline-part="round" hidden>
            <div class="game-status">
                <p class="game-score" data-skyline="round"></p>
                <div class="game-timer" aria-hidden="true"><span class="game-timer-bar" data-skyline="bar"></span></div>
                <p class="game-seconds"><span data-skyline="seconds"></span> s</p>
            </div>
            <p class="skyline-total">{{ __('Score') }} <strong data-skyline="score">0</strong></p>
            @include('game::_skyline-round', ['controls' => true, 'id' => 'skyline'])
            <div class="skyline-after">
                <p class="guessr-result" role="status" data-skyline="result"></p>
                <button type="button" class="btn btn-primary game-play" data-skyline-action="next" hidden>
                    <span class="material-icon" aria-hidden="true">skip_next</span><span data-skyline="next-label">{{ __('Next round') }}</span>
                </button>
            </div>
        </section>

        {{-- After a game: the score, and every round's. --}}
        <section class="game-panel game-over" data-skyline-part="over" hidden aria-labelledby="skyline-over-heading">
            <span class="material-icon game-over-icon" aria-hidden="true">sports_score</span>
            <h2 id="skyline-over-heading" tabindex="-1">{{ __('Game over') }}</h2>
            <p class="game-final">{{ __('Your score') }} <strong data-skyline="final">0</strong> <span class="muted" data-skyline="out-of"></span></p>
            <ol class="guessr-rounds skyline-rounds" data-skyline="rounds"></ol>
            <div class="game-over-actions">
                <button type="button" class="btn btn-primary game-play" data-skyline-action="again">
                    <span class="material-icon" aria-hidden="true">replay</span>{{ __('Play again') }}
                </button>
                <button type="button" class="btn btn-ghost" data-skyline-action="rename">{{ __('Change name') }}</button>
            </div>
        </section>
    </div>
@endif
</div>
@endsection
