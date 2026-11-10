@extends('layouts.public')

@section('title', 'Live game '.$game->spacedCode())
{{-- For the room to see: only the logo along the top. --}}
@section('presenting', 'yes')

@push('head')
    @if ($skyline)
        @include('game::_skyline-imports')
    @endif
    @if ($pins)
        <link rel="stylesheet" href="{{ versioned_asset('vendor/leaflet/leaflet.css') }}" />
    @endif
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    @if ($pins || $skyline)
        <link rel="stylesheet" href="{{ versioned_asset('modules/game/districtguessr.css') }}" />
    @endif
    @if ($skyline)
        <link rel="stylesheet" href="{{ versioned_asset('modules/game/skyline.css') }}" />
    @endif
    @if ($caseFile)
        <link rel="stylesheet" href="{{ versioned_asset('modules/game/case-file.css') }}" />
        <script src="{{ versioned_asset('modules/game/case-screen.js') }}" defer></script>
    @endif
    @if ($lineUp)
        <link rel="stylesheet" href="{{ versioned_asset('modules/game/line-up.css') }}" />
        <script src="{{ versioned_asset('modules/game/line-up-room.js') }}" defer></script>
    @endif
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/live.css') }}" />
    @if ($pins)
        <script src="{{ versioned_asset('vendor/leaflet/leaflet.js') }}" defer></script>
        <script src="{{ versioned_asset('modules/game/guessr-map.js') }}" defer></script>
    @endif
    @if ($skyline)
        <script src="{{ versioned_asset('modules/game/skyline-round.js') }}" defer></script>
    @endif
    <script src="{{ versioned_asset('modules/game/awake.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/live-host.js') }}" defer></script>
@endpush

{{-- A live game's big screen (LiveGameController@host), to put on a projector or share in a meeting: the code to join
     with and the players as they join, then each round's cards, its district on the map, its state's towers, its case or
     its line-up, with the
     time left and how many have answered, then its answer (how many said higher and lower, everyone's pins, or everyone's
     guesses as rings round the tower, the closest named) and the top 5, and at the end the podium and everyone's points. live-host.js shows it from what the game says about every
     second; Start, Show the answer and Next round move it on. In English, as the app is; the players' phones are in
     their own language. --}}
@section('content')
@php $join = preg_replace('#^https?://#', '', $joinUrl); @endphp
<div class="game-page game live-host" data-live-host data-token="{{ csrf_token() }}" data-state="{{ $urls['state'] }}"
    data-next="{{ $urls['next'] }}" data-close="{{ $urls['close'] }}" data-finish="{{ $urls['finish'] }}"
    @if ($pins) data-boundaries="{{ $boundaries }}" @endif @if ($skyline) data-skyline="{{ $boundaries }}" @endif
    @if ($caseFile) data-case-file="{{ $boundaries }}" @endif @if ($lineUp) data-line-up="{{ $boundaries }}" @endif>
    {{-- The game's music, for the room: the big screen is its speaker. --}}
    <div class="live-host-music">@include('game::_music', ['game' => $game->Game, 'small' => true])</div>
    <div class="live-host-head">
        @if ($pins)
            <h1 class="game-title">
                <img class="guessr-title" src="{{ versioned_asset('modules/game/title-districtguessr.svg') }}" alt="DistrictGuessr" lang="en" width="6273" height="1498" />
            </h1>
        @elseif ($skyline)
            <h1 class="game-title">
                <img class="skyline-title" src="{{ versioned_asset('modules/game/title-skyline.svg') }}" alt="Skyline" lang="en" width="5775" height="952" />
            </h1>
        @elseif ($caseFile)
            <h1 class="game-title">
                <img class="case-title" src="{{ versioned_asset('modules/game/title-case-file.svg') }}" alt="Case File" lang="en" width="5755" height="1610" />
            </h1>
        @elseif ($lineUp)
            <h1 class="game-title">
                <img class="lu-title" src="{{ versioned_asset('modules/game/title-line-up.svg') }}" alt="Line-Up" lang="en" width="2413" height="680" />
            </h1>
        @else
            @include('game::_title')
        @endif
        <div class="live-host-join" data-host="join">
            <p>Join at <strong>{{ $join }}</strong> with the code</p>
            <p class="live-code">{{ $game->spacedCode() }}</p>
        </div>
    </div>

    <noscript>
        <div class="alert alert-info">The big screen needs JavaScript. Turn it on in your browser to host the game.</div>
    </noscript>

    {{-- Before the game: the players as they join. --}}
    <section class="game-panel live-panel" data-host-part="lobby" hidden aria-labelledby="host-lobby-heading">
        <h2 id="host-lobby-heading">Waiting for players</h2>
        <p class="muted">
            On a phone, open <strong>{{ $join }}</strong> and type the code above. {{ $game->Rounds }} rounds of
            {{ $game->Seconds }} seconds each, and
            @if ($pins)
                the closer the pin, and the sooner, the more points.
            @elseif ($skyline)
                the closer the guess at the hidden tower's height, and the sooner, the more points.
            @elseif ($caseFile)
                a clue opens on the desk every {{ config('game.case_file.every') }} seconds, and the sooner a right
                accusation, the fewer clues it took and the more points.
            @elseif ($lineUp)
                the more of the line-up in its right order, most crime first, and the sooner, the more points.
            @else
                the sooner a right answer, the more points.
            @endif
            @if ($pins)
                {{ $easy ? 'On Easy: each map zooms into the district\'s state.' : 'On Hard: the whole of Malaysia.' }}
            @endif
            @if ($standing)
                Last One Standing: a wrong answer, or none, and you're out; if everyone still in gets it wrong, they all
                stay in. The last one in wins, or after {{ $game->Rounds }} rounds, whoever's left.
            @endif
        </p>
        <ul class="live-names" data-host="names"></ul>
        <p class="live-count" data-host="players"></p>
        <div class="live-host-actions">
            <button type="button" class="btn btn-primary game-play" data-host-action="next" disabled>
                <span class="material-icon" aria-hidden="true">play_arrow</span>Start the game
            </button>
        </div>
    </section>

    {{-- A few seconds to get ready for a round. --}}
    <section class="game-panel live-panel" data-host-part="ready" hidden>
        <p class="live-round" data-host="ready-round"></p>
        <p class="game-countdown" data-host="countdown" aria-hidden="true"></p>
        <p class="muted">Get ready…</p>
    </section>

    {{-- A round: the time left, how many have answered and the cards or the district; then the answer and the top 5,
         beside the map in DistrictGuessr. --}}
    <section class="game-board" data-host-part="round" hidden>
        <div class="game-status">
            <p class="game-score" data-host="round"></p>
            <div class="game-timer" aria-hidden="true"><span class="game-timer-bar" data-host="bar"></span></div>
            <p class="game-seconds"><span data-host="seconds"></span> s</p>
        </div>
        <p class="live-note" role="status" data-host="note"></p>
        <div class="live-host-play" data-host="play">
            @if ($pins)
                <div class="live-host-guessr">
                    <div class="guessr-ask">
                        <p class="guessr-prompt">Where is <strong data-host="district"></strong>?</p>
                        <span class="guessr-place"><span class="material-icon" aria-hidden="true">location_city</span><span data-host="region"></span></span>
                    </div>
                    <div class="guessr-map" data-host="map" role="img" aria-label="Map of Malaysia, with everyone's pins once the round is over"></div>
                </div>
            @elseif ($caseFile)
                <div class="live-host-case" data-host="case">
                    @include('game::_case-screen', ['mode' => 'host', 'id' => 'host-case'])
                </div>
            @elseif ($lineUp)
                <div class="live-host-lineup" data-host="lineup">
                    <p class="lu-ask"><span class="material-icon" aria-hidden="true" data-host="lu-icon"></span><span data-host="lu-type"></span><span
                        class="lu-ask-state" data-host="lu-state"></span><span class="lu-ask-year" data-host="lu-year"></span></p>
                    @include('game::_line-up-room', ['mode' => 'host'])
                </div>
            @elseif ($skyline)
                <div class="live-host-skyline" data-host="skyline">
                    @include('game::_skyline-round', ['controls' => false, 'id' => 'host-skyline'])
                </div>
            @else
                <div class="game-cards" data-host="cards"></div>
            @endif
            <div class="live-host-top" data-host="top-panel" hidden>
                <h3 class="live-board-heading">Top 5</h3>
                <ol class="live-board" data-host="top"></ol>
            </div>
        </div>
        <div class="live-host-actions">
            <button type="button" class="btn" data-host-action="close" hidden>Show the answer</button>
            <button type="button" class="btn btn-primary game-play" data-host-action="next" hidden>
                <span class="material-icon" aria-hidden="true">skip_next</span><span data-host="next-label">Next round</span>
            </button>
        </div>
    </section>

    {{-- The end: the podium and everyone's points. --}}
    <section class="game-panel live-panel" data-host-part="finished" hidden aria-labelledby="host-end-heading">
        <h2 id="host-end-heading">Final results</h2>
        <ol class="live-podium" data-host="podium"></ol>
        {{-- DistrictGuessr's awards, so more than the top 3 have something to cheer. --}}
        <div class="live-awards-panel" data-host="awards-panel" hidden>
            <h3 class="live-board-heading">Awards</h3>
            <ul class="live-awards" data-host="awards"></ul>
        </div>
        <ol class="live-board" data-host="board"></ol>
        <div class="live-host-actions">
            <a class="btn" href="{{ page_url('game-scores') }}">Back to Game scores</a>
        </div>
    </section>

    <div class="live-host-foot" data-host="foot">
        <button type="button" class="btn btn-ghost btn-sm" data-host-action="finish">End the game now</button>
    </div>
    @include('game::_music-credit', ['game' => $game->Game])

    @unless ($pins || $skyline || $caseFile || $lineUp)
        @include('game::_card')
    @endunless
    @if ($lineUp)
        @include('game::_line-up-sprite')
    @endif
</div>
@endsection
