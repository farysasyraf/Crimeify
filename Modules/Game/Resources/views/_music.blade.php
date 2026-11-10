{{-- A game's music switch (music.js, music.css), beside its page's All games: its track (config('game.music.tracks')),
     which starts with the first tap or key on the page, and the switch to turn it off or on again, which the browser
     remembers. Without JavaScript, there's no music, so no switch. $game is the game's key; $small, a small button. --}}
@php $track = config("game.music.tracks.{$game}"); @endphp
@if ($track)
    @once
        @push('head')
            <link rel="stylesheet" href="{{ versioned_asset('modules/game/music.css') }}" />
            <script src="{{ versioned_asset('modules/game/music.js') }}" defer></script>
        @endpush
    @endonce
    <button type="button" @class(['btn', 'btn-outline', 'game-music', 'btn-sm' => $small ?? false]) data-game-music="{{ versioned_asset($track['file']) }}"
        data-volume="{{ config('game.music.volume') }}" aria-pressed="true" hidden>
        <span class="material-icon" aria-hidden="true" data-game-music-icon>music_note</span>{{ __('Music') }}
    </button>
@endif
