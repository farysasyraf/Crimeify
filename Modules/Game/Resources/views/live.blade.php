@extends('layouts.public')

@section('title', __('Live game'))
@section('description', __('Join a live game with the code on the big screen.'))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('vendor/leaflet/leaflet.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/districtguessr.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/live.css') }}" />
    <script src="{{ versioned_asset('vendor/leaflet/leaflet.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/guessr-map.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/live.js') }}" defer></script>
@endpush

{{-- A live game on a phone (LivePlayController), for everyone without logging in, of Higher or Lower or DistrictGuessr:
     join with the code on the host's big screen and a name, wait for the host to start, then each round answer higher
     or lower, or pin the district on the map, the sooner the better, and at the end see your place and the top 10.
     live.js does it all, from what the game says about every second; the parts below are shown one at a time, in the
     page's language, and those marked with a game (data-live-game) only once the phone knows it's that game's.
     Reloading the page keeps the player in the game. --}}
@section('content')
<div class="game-page">
<div class="page-head">
    <div>
        <h1 data-live-game="none">{{ __('Join a live game') }}</h1>
        <div data-live-game="higher-or-lower" hidden>@include('game::_title')</div>
        <div data-live-game="districtguessr" hidden>
            <h1 class="game-title">
                <img class="guessr-title" src="{{ versioned_asset('modules/game/title-districtguessr.svg') }}" alt="DistrictGuessr" lang="en" width="6273" height="1498" />
            </h1>
        </div>
        <p class="muted">{{ __('A live game: everyone plays the same rounds at once, and the sooner the answer, the more points.') }}</p>
    </div>
    <a class="btn btn-outline game-live-link" href="{{ route('public.game') }}">
        <span class="material-icon" aria-hidden="true">apps</span>{{ __('All games') }}
    </a>
</div>

<div class="game live" data-live data-api="{{ $api }}" data-language="{{ $language }}" data-boundaries="{{ $boundaries }}">
    <noscript>
        <div class="alert alert-info">{{ __('The game needs JavaScript. Turn it on in your browser to play.') }}</div>
    </noscript>

    {{-- Joining: the code on the big screen, and a name. --}}
    <section class="game-panel" data-live-part="join" aria-labelledby="live-rules-heading">
        <div class="game-rules">
            <h2 id="live-rules-heading">{{ __('How to play') }}</h2>
            <ol>
                <li>{{ __('Type the code on the big screen and your name, then wait for the host to start.') }}</li>
                <li>{{ __('Everyone gets the same round at once: two cards to guess higher or lower, or a police district to pin on the map.') }}</li>
                <li>{{ __('The sooner you answer, the more points.') }}</li>
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

    {{-- In the game, waiting for the host to start it, with how to play it. --}}
    <section class="game-panel live-panel" data-live-part="lobby" hidden>
        <span class="material-icon live-panel-icon" aria-hidden="true">hourglass_top</span>
        <h2 data-live="welcome"></h2>
        <p class="muted">{{ __('Watch the big screen: the game starts when the host starts it.') }}</p>
        <p class="live-count" data-live="players"></p>
        <ol class="live-rules" data-live-game="higher-or-lower" hidden>
            <li>{{ __("Each round has two cards, each a police district's count of the same crime in one year.") }}</li>
            <li>{{ __("Guess whether the second card's count is higher or lower than the first's.") }}</li>
            <li>{{ __('A right guess scores up to :points points: the sooner, the more.', ['points' => number_format(config('game.live.points'))]) }}</li>
        </ol>
        <ol class="live-rules" data-live-game="districtguessr" hidden>
            <li>{{ __('Each round names a police district and its state: find it on the map.') }}</li>
            <li>{{ __('Tap the map to drop your pin, move it if you like, then press Confirm. :seconds seconds a district.', ['seconds' => $guessr['seconds']]) }}</li>
            <li>{{ __('Up to :points points a district, all of them within :km km: the closer, the more.', ['points' => number_format($guessr['points']), 'km' => $guessr['perfect']]) }}</li>
            <li>{{ __('The sooner you confirm, the more of them you keep: :share% at the last moment.', ['share' => $guessr['keeps']]) }}</li>
        </ol>
    </section>

    {{-- A few seconds to get ready for a round. --}}
    <section class="game-panel live-panel" data-live-part="ready" hidden>
        <p class="live-round" data-live="ready-round"></p>
        <p class="game-countdown" data-live="countdown" aria-hidden="true"></p>
        <p class="muted">{{ __('Get ready…') }}</p>
    </section>

    {{-- A round: the time left and the two cards, or the district and the map to pin it on; once answered, waiting for
         the others; then its answer, how the player did, and their points and place. --}}
    <section class="game-board" data-live-part="round" hidden>
        <div class="game-status">
            <p class="game-score" data-live="round"></p>
            <div class="game-timer" aria-hidden="true"><span class="game-timer-bar" data-live="bar"></span></div>
            <p class="game-seconds"><span data-live="seconds"></span> s</p>
        </div>
        <p class="live-note" role="status" data-live="note" hidden></p>
        <div class="game-cards" data-live="cards" data-live-game="higher-or-lower" hidden></div>
        <div data-live-game="districtguessr" hidden>
            <div class="guessr-ask">
                <p class="guessr-prompt">{{ __('Where is') }} <strong data-live="district"></strong>?</p>
                <span class="guessr-place"><span class="material-icon" aria-hidden="true">location_city</span><span data-live="region"></span></span>
            </div>
            {{-- The map, with Confirm over its foot, always in view. --}}
            <div class="guessr-stage">
                <div class="guessr-map" data-live="map" role="application" aria-label="{{ __('Map of Malaysia: tap where the district is') }}"></div>
                <div class="guessr-foot" data-live="foot">
                    <button type="button" class="btn btn-primary game-play" data-live="confirm" disabled>
                        <span class="material-icon" aria-hidden="true">where_to_vote</span>{{ __('Confirm') }}
                    </button>
                </div>
            </div>
        </div>
        <p class="live-standing" data-live="standing" hidden></p>
        <p class="game-keys muted" data-live-game="higher-or-lower" hidden>{{ __('Keys work too:') }} <kbd>↑</kbd> {{ __('higher') }}, <kbd>↓</kbd> {{ __('lower') }}</p>
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
