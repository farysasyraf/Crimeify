@extends('layouts.public')

{{-- The game's name, the same in Bahasa Melayu. --}}
@section('title', 'Skyline')
@section('description', __("A state's police districts as 3D towers, each as tall as its count of one crime. One is hidden: raise it to the height you think it is."))

@push('head')
    @include('game::_skyline-imports')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/districtguessr.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/skyline.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/skyline-sheet.css') }}" />
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
    <div class="game-head-actions">
        @include('game::_music', ['game' => 'skyline'])
        <a class="btn btn-outline game-live-link" href="{{ route('public.game') }}">
            <span class="material-icon" aria-hidden="true">apps</span>{{ __('All games') }}
        </a>
    </div>
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

        {{-- Before a game, its cover: an architect's blueprint (skyline-sheet.css). The drawing of the towers, the one to
             guess dashed amber with its height a "?" (blueprint-skyline.svg, drawn by make_art.py, in the page so it
             can draw itself in); how to play, as the drawing's notes; and its title block, with the project, the
             rounds and their time, the architect's name to play as, and the stamp to start. --}}
        <section class="sky-sheet sky-cover" data-skyline-part="intro" aria-labelledby="skyline-rules-heading">
            <div class="sky-drawing">{!! file_get_contents(public_path('modules/game/blueprint-skyline.svg')) !!}</div>
            <div class="sky-notes">
                <h2 class="sky-label" id="skyline-rules-heading">{{ __('How to play') }}</h2>
                <ol>
                    <li>{{ __("Each round shows a state's police districts as towers, each as tall as its count of one crime in one year.") }}</li>
                    <li>{{ __('One tower is a glass outline: raise it with the slider to how tall you think it is, then press Confirm. :seconds seconds a round.', ['seconds' => $seconds]) }}</li>
                    <li>{{ __('Up to :points points a round, all of them within :perfect%: the closer, the more.', ['points' => number_format($points), 'perfect' => $perfect]) }}</li>
                    <li>{{ __(':rounds rounds a game.', ['rounds' => $rounds]) }}</li>
                    <li>{{ __('Drag to turn the towers, pinch or scroll to zoom, and tap one for its count.') }}</li>
                </ol>
            </div>
            <form class="sky-block game-start" data-skyline-start novalidate>
                @csrf
                <p class="sky-cell sky-cell-project"><span class="sky-label">{{ __('Project') }}</span> <strong lang="en">Skyline</strong></p>
                <p class="sky-cell"><span class="sky-label">{{ __('Rounds') }}</span> <strong>{{ $rounds }}</strong></p>
                <p class="sky-cell"><span class="sky-label">{{ __('Each round') }}</span> <strong>{{ __(':seconds s', ['seconds' => $seconds]) }}</strong></p>
                <div class="sky-cell sky-cell-name">
                    <label class="sky-label" for="skyline-name">{{ __('Architect') }}</label>
                    <input class="sky-name" type="text" id="skyline-name" name="name" maxlength="{{ $nameLength }}" autocomplete="name" required
                        placeholder="{{ __('Your name') }}" aria-describedby="skyline-name-note" />
                </div>
                <div class="sky-cell sky-cell-start">
                    <p class="game-error sky-error" role="alert" data-skyline-error hidden></p>
                    <button type="submit" class="sky-stamp">
                        <span class="material-icon" aria-hidden="true">architecture</span>{{ __('Start building') }}
                    </button>
                    <p class="sky-small" id="skyline-name-note">{{ __("Your name and score go to :app's administrators, who choose the winner. Only they see the scores.", ['app' => config('app.name')]) }}</p>
                </div>
            </form>
        </section>

        {{-- A few seconds counting down before the first round, on the blueprint's paper. --}}
        <section class="game-panel game-ready sky-sheet sky-ready" data-skyline-part="ready" hidden>
            <p class="sky-label">{{ __('Get ready…') }}</p>
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

        {{-- After a game, the inspection report, on the blueprint's paper: who built it, every round's tower as built (the
             guess, dashed amber) beside it as it really is (the count), with its figures and points, and the score,
             stamped INSPECTED. --}}
        <section class="sky-sheet sky-report" data-skyline-part="over" hidden aria-labelledby="skyline-over-heading">
            <header class="sky-report-head">
                <h2 class="sky-report-title" id="skyline-over-heading" tabindex="-1">{{ __('Inspection report') }}</h2>
                <p class="sky-report-architect"><span class="sky-label">{{ __('Architect') }}</span> <strong data-skyline="architect"></strong></p>
            </header>
            <p class="sky-report-key" aria-hidden="true">
                <span><span class="sky-swatch is-guess"></span>{{ __('Your guess') }}</span>
                <span><span class="sky-swatch is-real"></span>{{ __('The real count') }}</span>
            </p>
            <ol class="sky-rounds" data-skyline="rounds"></ol>
            <div class="sky-report-foot">
                <p class="sky-report-total"><span class="sky-label">{{ __('Your score') }}</span> <strong data-skyline="final">0</strong> <span class="sky-label" data-skyline="out-of"></span></p>
                <span class="sky-inspected" aria-hidden="true">{{ __('Inspected') }}</span>
                <div class="sky-report-actions">
                    <button type="button" class="sky-stamp" data-skyline-action="again">
                        <span class="material-icon" aria-hidden="true">replay</span>{{ __('Play again') }}
                    </button>
                    <button type="button" class="sky-link" data-skyline-action="rename">{{ __('Change name') }}</button>
                </div>
            </div>
        </section>
    </div>
@endif
@include('game::_music-credit', ['game' => 'skyline'])
</div>
@endsection
