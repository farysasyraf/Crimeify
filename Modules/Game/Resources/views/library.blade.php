@extends('layouts.public')

@section('title', __('Games'))
@section('description', __("Games with Malaysia's crime figures, to play on your own or with a room."))
{{-- The backdrops fill the page between the bar and the footer. --}}
@section('main-class', 'main-full')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/game.css') }}" />
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/library.css') }}" />
    <script src="{{ versioned_asset('modules/game/library.js') }}" defer></script>
@endpush

{{-- The Games page (LibraryController), as a games console's home screen: a row of tiles along the top, one a game
     (Library), and the chosen one's title, what it is, its buttons and a few cards about it, over its own backdrop.
     library.js makes the tiles choose a game, with ← and →, and open it, with Enter or a second click; without
     JavaScript, each tile opens its game, and every game is listed one after the other. --}}
@section('content')
<div class="library" data-library style="--doodles: url('{{ versioned_asset('modules/game/doodles.svg') }}')">
    {{-- Each game's backdrop, behind everything: the chosen one's shows. --}}
    @foreach ($games as $game)
        <div @class(['library-art', "library-art-{$game['key']}", 'is-shown' => $loop->first]) data-library-art="{{ $game['key'] }}" aria-hidden="true"></div>
    @endforeach

    <div class="library-inner">
        <h1 class="visually-hidden">{{ __('Games') }}</h1>

        <ul class="library-tiles" data-library-tiles aria-label="{{ __('Games') }}">
            @foreach ($games as $game)
                <li>
                    <a href="{{ $game['actions'][0]['url'] ?? '#game-'.$game['key'] }}" id="tile-{{ $game['key'] }}"
                        @class(['library-tile', 'is-selected' => $loop->first]) data-library-tile="{{ $game['key'] }}">
                        @isset($game['tile']['image'])
                            <img src="{{ versioned_asset($game['tile']['image']) }}" alt="" width="600" height="600" />
                        @else
                            <span class="material-icon" aria-hidden="true">{{ $game['tile']['icon'] }}</span>
                        @endisset
                        <span class="library-tile-name">{{ $game['name'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        @foreach ($games as $game)
            <section @class(['library-game', 'is-shown' => $loop->first]) id="game-{{ $game['key'] }}" data-library-game="{{ $game['key'] }}"
                aria-labelledby="name-{{ $game['key'] }}">
                <div class="library-hero">
                    <div class="library-about">
                        @if ($game['title'])
                            {{-- A drawn title, in English as it shows, the game's name as its alt. --}}
                            <h2 class="library-title" id="name-{{ $game['key'] }}">
                                <img src="{{ versioned_asset($game['title']['image']) }}" alt="{{ $game['name'] }}" lang="en"
                                    width="{{ $game['title']['width'] }}" height="{{ $game['title']['height'] }}" />
                            </h2>
                        @else
                            <h2 class="library-heading" id="name-{{ $game['key'] }}">{{ $game['name'] }}</h2>
                        @endif
                        <p class="library-text">{{ $game['about'] }}</p>
                        @if ($game['actions'])
                            <div class="library-actions">
                                @foreach ($game['actions'] as $action)
                                    <a @class(['btn', 'btn-primary library-play' => $loop->first, 'library-more' => ! $loop->first]) href="{{ $action['url'] }}">
                                        <span class="material-icon" aria-hidden="true">{{ $action['icon'] }}</span>{{ $action['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Beside it: for Higher or Lower, a round of it, from the figures; for a game with one, its picture; for the
                         others, their icon. --}}
                    @if ($game['key'] === 'higher-or-lower' && $sample)
                        <div class="library-sample" aria-hidden="true">
                            @foreach ($sample as $face)
                                <div @class(['game-card', 'game-card-next' => ! isset($face['crimes'])])>
                                    <span class="material-icon game-card-icon">{{ $face['icon'] }}</span>
                                    <p class="game-card-type">{{ $face['type'] }}</p>
                                    <p class="game-card-district">{{ $face['district'] }}</p>
                                    <p class="game-card-where">{{ $face['place'] }} · {{ $face['year'] }}</p>
                                    <div class="game-card-foot">
                                        <p class="game-card-count"><strong>{{ isset($face['crimes']) ? number_format($face['crimes']) : '?' }}</strong> <span>{{ __('crimes') }}</span></p>
                                    </div>
                                </div>
                            @endforeach
                            <span class="game-vs">VS</span>
                        </div>
                    @elseif (isset($game['picture']))
                        <figure class="library-picture">
                            <img src="{{ versioned_asset($game['picture']['image']) }}" alt=""
                                width="{{ $game['picture']['width'] }}" height="{{ $game['picture']['height'] }}" />
                            @isset($game['picture']['credit'])
                                <figcaption>{{ $game['picture']['credit'] }}</figcaption>
                            @endisset
                        </figure>
                    @elseif (isset($game['tile']['icon']))
                        <span class="material-icon library-glyph" aria-hidden="true">{{ $game['tile']['big'] ?? $game['tile']['icon'] }}</span>
                    @endif
                </div>

                @if ($game['cards'])
                    <ul class="library-cards">
                        @foreach ($game['cards'] as $card)
                            <li class="library-card">
                                <span class="material-icon" aria-hidden="true">{{ $card['icon'] }}</span>
                                <h3>{{ $card['title'] }}</h3>
                                <p>{{ $card['text'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
</div>
@endsection
