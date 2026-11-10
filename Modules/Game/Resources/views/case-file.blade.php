@extends('layouts.public')

{{-- The game's name, the same in Bahasa Melayu. --}}
@section('title', 'Case File')
@section('description', __('A mystery police district, and the clues on a detective\'s desk: open as few as you dare, then pick it out from six suspects.'))

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/case-file.css') }}" />
    <script src="{{ versioned_asset('modules/game/case-screen.js') }}" defer></script>
    <script src="{{ versioned_asset('modules/game/case-file.js') }}" defer></script>
@endpush

{{-- Case File, for everyone without logging in: each case a mystery police district, its clues on a detective's desk in
     pixels, to pick out from six suspects, five times, all played in one pixel screen (_case-screen.blade.php), from the
     case file's cover to the closing report. case-file.js plays it, asking CaseFileController@start, @clue, @accuse and
     @next, which keep the cases and the score; case-screen.js runs the screen. Only administrators see the scores, on
     the Game scores page. --}}
@section('content')
<div class="game-page case-page">
    <div class="case-page-head">
        <h1 class="visually-hidden">Case File</h1>
        <div class="game-head-actions">
            @include('game::_music', ['game' => 'case-file', 'small' => true])
            <a class="btn btn-outline btn-sm" href="{{ route('public.game') }}">
                <span class="material-icon" aria-hidden="true">apps</span>{{ __('All games') }}
            </a>
        </div>
    </div>

    @if (! $ready)
        <div class="empty">
            <p>{{ __('There are no crime figures to play with yet.') }}</p>
        </div>
    @else
        <div class="case-file" data-case-file data-start="{{ $urls['start'] }}" data-clue="{{ $urls['clue'] }}" data-accuse="{{ $urls['accuse'] }}"
            data-next="{{ $urls['next'] }}" data-boundaries="{{ $boundaries }}" data-seconds="{{ $seconds }}" data-cases="{{ $cases }}"
            data-points="{{ $points }}">
            <noscript>
                <div class="alert alert-info">{{ __('The game needs JavaScript. Turn it on in your browser to play.') }}</div>
            </noscript>
            @include('game::_case-screen', ['mode' => 'solo', 'id' => 'case'])
        </div>
    @endif
    @include('game::_music-credit', ['game' => 'case-file'])
</div>
@endsection
