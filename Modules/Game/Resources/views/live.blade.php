@extends('layouts.public')

{{-- The game's name stays in English in Bahasa Melayu too, as its title is drawn. --}}
@section('title', 'Higher or Lower')
@section('description', __('Join a live game of Higher or Lower with the code on the big screen.'))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/live.css') }}" />
    <script src="{{ versioned_asset('modules/game/live.js') }}" defer></script>
@endpush

{{-- A live game on a phone (LivePlayController), for everyone without logging in: join with the code on the host's big
     screen and a name, wait for the host to start, then each round answer higher or lower, the sooner the better, and
     at the end see your place and the top 10. live.js does it all, from what the game says about every second; the
     parts below are shown one at a time, in the page's language. Reloading the page keeps the player in the game. --}}
@section('content')
<div class="game-page">
<div class="page-head">
    <div>
        @include('game::_title')
        <p class="muted">{{ __('A live game: everyone plays the same rounds at once, and the fastest right answers score the most.') }}</p>
    </div>
    <a class="btn btn-outline game-live-link" href="{{ route('public.game') }}">
        <span class="material-icon" aria-hidden="true">person</span>{{ __('Play on your own') }}
    </a>
</div>

<div class="game live" data-live data-api="{{ $api }}" data-language="{{ $language }}">
    <noscript>
        <div class="alert alert-info">{{ __('The game needs JavaScript. Turn it on in your browser to play.') }}</div>
    </noscript>

    {{-- Joining: the code on the big screen, and a name. --}}
    <section class="game-panel" data-live-part="join" aria-labelledby="live-rules-heading">
        <div class="game-rules">
            <h2 id="live-rules-heading">{{ __('How to play') }}</h2>
            <ol>
                <li>{{ __('Type the code on the big screen and your name, then wait for the host to start.') }}</li>
                <li>{{ __("Each round has two cards, each a police district's count of the same crime in one year.") }}</li>
                <li>{{ __("Guess whether the second card's count is higher or lower than the first's.") }}</li>
                <li>{{ __('A right guess scores up to :points points: the sooner, the more.', ['points' => number_format(config('game.live.points'))]) }}</li>
            </ol>
        </div>
        <form class="game-start" data-live-join novalidate>
            <div class="field">
                <label for="live-code">{{ __('Game code') }}</label>
                <input type="text" id="live-code" name="code" class="live-code-field" value="{{ $code }}" inputmode="numeric"
                    maxlength="7" autocomplete="off" required />
            </div>
            <div class="field">
                <label for="live-name">{{ __('Your name') }}</label>
                <input type="text" id="live-name" name="name" maxlength="{{ $nameLength }}" autocomplete="name" required
                    aria-describedby="live-name-note" />
            </div>
            <p class="game-error" role="alert" data-live-error hidden></p>
            <button type="submit" class="btn btn-primary game-play">
                <span class="material-icon" aria-hidden="true">login</span>{{ __('Join') }}
            </button>
            <p class="game-note muted" id="live-name-note">{{ __("Your name and points show on the big screen, and at the end on everyone's phone.") }}</p>
        </form>
    </section>

    {{-- In the game, waiting for the host to start it. --}}
    <section class="game-panel live-panel" data-live-part="lobby" hidden>
        <span class="material-icon live-panel-icon" aria-hidden="true">hourglass_top</span>
        <h2 data-live="welcome"></h2>
        <p class="muted">{{ __('Watch the big screen: the game starts when the host starts it.') }}</p>
        <p class="live-count" data-live="players"></p>
    </section>

    {{-- A few seconds to get ready for a round. --}}
    <section class="game-panel live-panel" data-live-part="ready" hidden>
        <p class="live-round" data-live="ready-round"></p>
        <p class="live-countdown" data-live="countdown" aria-hidden="true"></p>
        <p class="muted">{{ __('Get ready…') }}</p>
    </section>

    {{-- A round: the time left and the two cards; once answered, waiting for the others; then its answer, how the
         player did, and their points and place. --}}
    <section class="game-board" data-live-part="round" hidden>
        <div class="game-status">
            <p class="game-score" data-live="round"></p>
            <div class="game-timer" aria-hidden="true"><span class="game-timer-bar" data-live="bar"></span></div>
            <p class="game-seconds"><span data-live="seconds"></span> s</p>
        </div>
        <p class="live-note" role="status" data-live="note" hidden></p>
        <div class="game-cards" data-live="cards"></div>
        <p class="live-standing" data-live="standing" hidden></p>
        <p class="game-keys muted">{{ __('Keys work too:') }} <kbd>↑</kbd> {{ __('higher') }}, <kbd>↓</kbd> {{ __('lower') }}</p>
    </section>

    {{-- The end: the player's place and points, and the top 10. --}}
    <section class="game-panel live-panel" data-live-part="finished" hidden aria-labelledby="live-end-heading">
        <span class="material-icon game-over-icon" aria-hidden="true">emoji_events</span>
        <h2 id="live-end-heading" tabindex="-1">{{ __('Game over') }}</h2>
        <p class="live-place" data-live="place"></p>
        <p class="game-final">{{ __('Your points') }} <strong data-live="final"></strong></p>
        <h3 class="live-board-heading">{{ __('Leaderboard') }}</h3>
        <ol class="live-board" data-live="board"></ol>
        <a class="btn btn-primary game-play" href="{{ route('public.game.live') }}" data-live="again">
            <span class="material-icon" aria-hidden="true">login</span>{{ __('Join another game') }}
        </a>
    </section>

    @include('game::_card')
</div>
</div>
@endsection
