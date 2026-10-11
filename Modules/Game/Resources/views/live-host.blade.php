@extends('layouts.public')

@section('title', 'Live game '.$game->spacedCode())
{{-- For the room to see: only the logo along the top. --}}
@section('presenting', 'yes')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/live.css') }}" />
    <script src="{{ versioned_asset('modules/game/live-host.js') }}" defer></script>
@endpush

{{-- A live game's big screen (LiveGameController@host), to put on a projector or share in a meeting: the code to join
     with and the players as they join, then each round's cards with the time left and how many have answered, then its
     answer, how many said higher and lower, and the top 5, and at the end the podium and everyone's points. live-host.js
     shows it from what the game says about every second; Start, Show the answer and Next round move it on. In English,
     as the app is; the players' phones are in their own language. --}}
@section('content')
@php $join = preg_replace('#^https?://#', '', $joinUrl); @endphp
<div class="game-page game live-host" data-live-host data-token="{{ csrf_token() }}" data-state="{{ $urls['state'] }}"
    data-next="{{ $urls['next'] }}" data-close="{{ $urls['close'] }}" data-finish="{{ $urls['finish'] }}">
    <div class="live-host-head">
        @include('game::_title')
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
            {{ $game->Seconds }} seconds each, and the sooner a right answer, the more points.
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
        <p class="live-countdown" data-host="countdown" aria-hidden="true"></p>
        <p class="muted">Get ready…</p>
    </section>

    {{-- A round: the time left, how many have answered and the cards; then the answer and the top 5. --}}
    <section class="game-board" data-host-part="round" hidden>
        <div class="game-status">
            <p class="game-score" data-host="round"></p>
            <div class="game-timer" aria-hidden="true"><span class="game-timer-bar" data-host="bar"></span></div>
            <p class="game-seconds"><span data-host="seconds"></span> s</p>
        </div>
        <p class="live-note" role="status" data-host="note"></p>
        <div class="game-cards" data-host="cards"></div>
        <div class="live-host-top" data-host="top-panel" hidden>
            <h3 class="live-board-heading">Top 5</h3>
            <ol class="live-board" data-host="top"></ol>
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
        <ol class="live-board" data-host="board"></ol>
        <div class="live-host-actions">
            <a class="btn" href="{{ page_url('game-scores') }}">Back to Game scores</a>
        </div>
    </section>

    <div class="live-host-foot" data-host="foot">
        <button type="button" class="btn btn-ghost btn-sm" data-host-action="finish">End the game now</button>
    </div>

    @include('game::_card')
</div>
@endsection
