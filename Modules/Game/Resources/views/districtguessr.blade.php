@extends('layouts.public')

{{-- The game's name, the same in Bahasa Melayu. --}}
@section('title', 'DistrictGuessr')
@section('description', __("Where's the police district? Pin it on a map of Malaysia with only its states' outlines: the closer, the more points."))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('vendor/leaflet/leaflet.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/districtguessr.css') }}" />
    <script src="{{ versioned_asset('vendor/leaflet/leaflet.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/guessr-map.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/districtguessr.js') }}" defer></script>
@endpush

{{-- DistrictGuessr, for everyone without logging in: a police district's name and its state, to find on a map of
     Malaysia with only its states' outlines (Leaflet, without a street map, so no town's name gives it away), ten times.
     districtguessr.js plays it, asking DistrictGuessrController@start, @guess and @next, which keep the districts and
     the score. Only administrators see the scores, on the Game scores page. --}}
@section('content')
<div class="game-page">
<div class="page-head">
    <div>
        {{-- The title as drawn (Resources/title/make_title.py), its alt the game's name. --}}
        <h1 class="game-title">
            <img class="guessr-title" src="{{ versioned_asset('modules/game/title-districtguessr.svg') }}" alt="DistrictGuessr" lang="en" width="6273" height="1498" />
        </h1>
        <p class="muted">{{ __("Where's the police district? Pin it on a map of Malaysia with only its states' outlines: the closer, the more points.") }}</p>
    </div>
    <a class="btn btn-outline game-live-link" href="{{ route('public.game') }}">
        <span class="material-icon" aria-hidden="true">apps</span>{{ __('All games') }}
    </a>
</div>

@if (! $ready)
    <div class="empty">
        <p>{{ __('There are no police districts to play with yet.') }}</p>
    </div>
@else
    <div class="game guessr" data-guessr data-start="{{ $urls['start'] }}" data-guess="{{ $urls['guess'] }}" data-next="{{ $urls['next'] }}"
        data-boundaries="{{ $boundaries }}" data-seconds="{{ $seconds }}" data-rounds="{{ $rounds }}" data-points="{{ $points }}">
        <noscript>
            <div class="alert alert-info">{{ __('The game needs JavaScript. Turn it on in your browser to play.') }}</div>
        </noscript>

        {{-- Before a game: how to play, and the name to play as. --}}
        <section class="game-panel game-intro" data-guessr-part="intro" aria-labelledby="guessr-rules-heading">
            <div class="game-rules">
                <h2 id="guessr-rules-heading">{{ __('How to play') }}</h2>
                <ol>
                    <li>{{ __('Each round names a police district and its state: find it on the map.') }}</li>
                    <li>{{ __('Tap the map to drop your pin, move it if you like, then press Confirm. :seconds seconds a district.', ['seconds' => $seconds]) }}</li>
                    <li>{{ __('Up to :points points a district, all of them within :km km: the closer, the more.', ['points' => number_format($points), 'km' => $perfect]) }}</li>
                    <li>{{ __(':rounds districts a game.', ['rounds' => $rounds]) }}</li>
                </ol>
            </div>
            <form class="game-start" data-guessr-start novalidate>
                @csrf
                <div class="field">
                    <label for="guessr-name">{{ __('Your name') }}</label>
                    <input type="text" id="guessr-name" name="name" maxlength="{{ $nameLength }}" autocomplete="name" required aria-describedby="guessr-name-note" />
                </div>
                <p class="game-error" role="alert" data-guessr-error hidden></p>
                <button type="submit" class="btn btn-primary game-play">
                    <span class="material-icon" aria-hidden="true">play_arrow</span>{{ __('Start') }}
                </button>
                <p class="game-note muted" id="guessr-name-note">{{ __("Your name and score go to :app's administrators, who choose the winner. Only they see the scores.", ['app' => config('app.name')]) }}</p>
            </form>
        </section>

        {{-- A few seconds counting down before the first district. --}}
        <section class="game-panel game-ready" data-guessr-part="ready" hidden>
            <p class="muted">{{ __('Get ready…') }}</p>
            <p class="game-countdown" data-guessr="countdown" aria-hidden="true"></p>
        </section>

        {{-- A round: the district, the time left, the map to pin it on; then how far off the pin was, and its points. --}}
        <section class="guessr-board" data-guessr-part="round" hidden>
            <div class="game-status">
                <p class="game-score" data-guessr="round"></p>
                <div class="game-timer" aria-hidden="true"><span class="game-timer-bar" data-guessr="bar"></span></div>
                <p class="game-seconds"><span data-guessr="seconds"></span> s</p>
            </div>
            <div class="guessr-ask">
                <p class="guessr-prompt">{{ __('Where is') }} <strong data-guessr="district"></strong>?</p>
                <span class="guessr-place"><span class="material-icon" aria-hidden="true">location_city</span><span data-guessr="place"></span></span>
                <p class="guessr-total">{{ __('Score') }} <strong data-guessr="score">0</strong></p>
            </div>
            {{-- The map, with how far off the pin was and Confirm or Next district over its foot, always in view. --}}
            <div class="guessr-stage">
                <div class="guessr-map" data-guessr="map" role="application" aria-label="{{ __('Map of Malaysia: tap where the district is') }}"></div>
                <div class="guessr-foot">
                    <p class="guessr-result" role="status" data-guessr="result"></p>
                    <button type="button" class="btn btn-primary game-play" data-guessr-action="confirm" disabled>
                        <span class="material-icon" aria-hidden="true">where_to_vote</span>{{ __('Confirm') }}
                    </button>
                    <button type="button" class="btn btn-primary game-play" data-guessr-action="next" hidden>
                        <span class="material-icon" aria-hidden="true">skip_next</span><span data-guessr="next-label">{{ __('Next district') }}</span>
                    </button>
                </div>
            </div>
        </section>

        {{-- After a game: the score, and every district's. --}}
        <section class="game-panel game-over" data-guessr-part="over" hidden aria-labelledby="guessr-over-heading">
            <span class="material-icon game-over-icon" aria-hidden="true">sports_score</span>
            <h2 id="guessr-over-heading" tabindex="-1">{{ __('Game over') }}</h2>
            <p class="game-final">{{ __('Your score') }} <strong data-guessr="final">0</strong> <span class="muted" data-guessr="out-of"></span></p>
            <ol class="guessr-rounds" data-guessr="rounds"></ol>
            <div class="game-over-actions">
                <button type="button" class="btn btn-primary game-play" data-guessr-action="again">
                    <span class="material-icon" aria-hidden="true">replay</span>{{ __('Play again') }}
                </button>
                <button type="button" class="btn btn-ghost" data-guessr-action="rename">{{ __('Change name') }}</button>
            </div>
        </section>
    </div>
@endif
</div>
@endsection
