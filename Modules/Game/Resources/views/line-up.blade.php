@extends('layouts.public')

{{-- The game's name, the same in Bahasa Melayu. --}}
@section('title', 'Line-Up')
@section('description', __('Five police districts in a line-up. Put them in order of their crime, from most to least.'))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/line-up.css') }}" />
    <script src="{{ versioned_asset('modules/game/line-up-room.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/line-up.js') }}" defer></script>
@endpush

{{-- Line-Up, for everyone without logging in: each round five police districts of one state in a line-up, to put in
     order of their count of one crime in one year, most first, ten times. It opens on the witness's side of the one-way
     mirror, with the instructions on a clipboard and the name to play as, and ends on the witness statement. line-up.js
     plays it, asking LineUpController@start, @order and @next, which keep the rounds and the score; line-up-room.js
     draws the room. Only administrators see the scores, on the Game scores page. --}}
@section('content')
<div class="game-page lu-page">
<div class="page-head lu-page-head">
    <h1 class="visually-hidden">Line-Up</h1>
    <div class="game-head-actions">
        @include('game::_music', ['game' => 'line-up'])
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
    @include('game::_line-up-sprite')
    <div class="game lu" data-line-up data-start="{{ $urls['start'] }}" data-order="{{ $urls['order'] }}" data-next="{{ $urls['next'] }}"
        data-boundaries="{{ $boundaries }}" data-seconds="{{ $seconds }}" data-rounds="{{ $rounds }}" data-most="{{ $most }}">
        <noscript>
            <div class="alert alert-info">{{ __('The game needs JavaScript. Turn it on in your browser to play.') }}</div>
        </noscript>

        {{-- Before a game, behind the one-way mirror: the room through the glass, the title, and on a clipboard the
             witness's instructions, the name to play as, and the stamp to start. --}}
        <section class="lu-cover" data-line-up-part="intro" aria-labelledby="line-up-rules-heading">
            <div class="lu-cover-room" aria-hidden="true">
                <div class="lu-wall"></div>
                <div class="lu-light"></div>
                <ol class="lu-line">
                    @foreach (['cap', 'hoodie', 'glasses', 'bun', 'collar'] as $look)
                        <li class="lu-slot">
                            <span class="lu-suspect">
                                <span class="lu-figure">
                                    <svg class="lu-art" viewBox="0 0 120 260"><use href="#lu-{{ $look }}" /></svg>
                                    <span class="lu-board"><span class="lu-name">?</span>
                                        <svg class="lu-hand is-left" viewBox="0 0 30 40"><use href="#lu-hand" /></svg>
                                        <svg class="lu-hand is-right" viewBox="0 0 30 40"><use href="#lu-hand" /></svg>
                                    </span>
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ol>
                <div class="lu-floor">@for ($place = 1; $place <= $suspects; $place++)<span class="lu-place"><b class="lu-place-number">{{ $place }}</b></span>@endfor</div>
            </div>
            <div class="lu-glass" aria-hidden="true"></div>
            <div class="lu-cover-title">
                <img class="lu-title" src="{{ versioned_asset('modules/game/title-line-up.svg') }}" alt="" width="2413" height="680" />
                <p class="lu-tagline">{{ __('Five police districts in a line-up. Put them in order of their crime, from most to least.') }}</p>
            </div>
            <div class="lu-clipboard">
                <span class="lu-clip" aria-hidden="true"></span>
                <form class="lu-paper game-start" data-line-up-start novalidate>
                    @csrf
                    <h2 id="line-up-rules-heading">{{ __('Witness instructions') }}</h2>
                    <ol class="lu-rules">
                        <li>{{ __(':count police districts of one state stand in the line-up, each holding its name.', ['count' => $suspects]) }}</li>
                        <li>{{ __('Put them in order for the crime and year shown, most on the left. Tap two to swap them, or drag one along the line.') }}</li>
                        <li>{{ __(':right points for each in its place, :near if it\'s one place off: up to :most a round.', ['right' => $right, 'near' => $near, 'most' => number_format($most)]) }}</li>
                        <li>{{ __(':rounds rounds, :seconds seconds each. The map shows where each one is.', ['rounds' => $rounds, 'seconds' => $seconds]) }}</li>
                    </ol>
                    <label class="lu-witness-label" for="line-up-name">{{ __('Witness') }}</label>
                    <input class="lu-witness" type="text" id="line-up-name" name="name" maxlength="{{ $nameLength }}" autocomplete="name" required
                        placeholder="{{ __('Your name') }}" aria-describedby="line-up-name-note" />
                    <p class="game-error lu-error" role="alert" data-line-up-error hidden></p>
                    <button type="submit" class="lu-bring">{{ __('Bring them in') }}</button>
                    <p class="lu-note" id="line-up-name-note">{{ __("Your name and score go to :app's administrators, who choose the winner. Only they see the scores.", ['app' => config('app.name')]) }}</p>
                </form>
            </div>
        </section>

        {{-- A round: the crime, state and year, the time left and the score; then the room, with Confirm along its foot,
             then how the order did, with the next round. The countdown before the first is over the empty room. --}}
        <section class="lu-game" data-line-up-part="round" hidden>
            <div class="lu-hud">
                <p class="lu-round" data-line-up="round"></p>
                <p class="lu-ask"><span class="material-icon" aria-hidden="true" data-line-up="icon"></span><span data-line-up="type"></span><span
                    class="lu-ask-state" data-line-up="state"></span><span class="lu-ask-year" data-line-up="year"></span></p>
                <div class="lu-timer" aria-hidden="true"><span class="lu-timer-bar" data-line-up="bar"></span></div>
                <p class="lu-seconds"><span data-line-up="seconds"></span> s</p>
                <p class="lu-score">{{ __('Score') }} <strong data-line-up="score">0</strong></p>
            </div>
            @include('game::_line-up-room', ['mode' => 'solo'])
        </section>

        {{-- After a game, the witness statement: every round's suspects in the right order, each marked by where they
             stood in the line-up given, its points, and the score. --}}
        <section class="lu-statement-wrap" data-line-up-part="over" hidden aria-labelledby="line-up-over-heading">
            <div class="lu-clipboard lu-statement">
                <span class="lu-clip" aria-hidden="true"></span>
                <div class="lu-paper">
                    <h2 id="line-up-over-heading" tabindex="-1">{{ __('Witness statement') }}</h2>
                    <p class="lu-statement-witness"><span>{{ __('Witness') }}</span> <strong data-line-up="witness"></strong></p>
                    <p class="lu-statement-key">
                        <span class="lu-key is-right">✓ {{ __('in its place') }}</span>
                        <span class="lu-key is-near">{{ __('one place off') }}</span>
                        <span class="lu-key is-wrong">{{ __('further off') }}</span>
                    </p>
                    <ol class="lu-statement-rounds" data-line-up="rounds"></ol>
                    <p class="lu-statement-total"><span>{{ __('Your score') }}</span> <strong data-line-up="final">0</strong> <span data-line-up="out-of"></span></p>
                    <span class="lu-statement-stamp" aria-hidden="true">{{ __('Statement taken') }}</span>
                    <div class="lu-statement-actions">
                        <button type="button" class="lu-bring" data-line-up-action="again">{{ __('Play again') }}</button>
                        <button type="button" class="lu-link" data-line-up-action="rename">{{ __('Change name') }}</button>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endif
@include('game::_music-credit', ['game' => 'line-up'])
</div>
@endsection
