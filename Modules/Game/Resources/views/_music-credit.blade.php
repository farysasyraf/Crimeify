{{-- A game's music credit, at the foot of its page, as its licence asks: the track's title, who made it, and the
     licence (config('game.music')). $game is the game's key. --}}
@php
    $track = config("game.music.tracks.{$game}");
    $licence = config('game.music.licence');
@endphp
@if ($track)
    <p class="game-music-credit">
        {!! __('Music: :track, licensed under :licence.', [
            'track' => e('“'.$track['title'].'” '.$track['artist']),
            'licence' => '<a href="'.e($licence['url']).'" rel="license">'.e($licence['name']).'</a>',
        ]) !!}
    </p>
@endif
