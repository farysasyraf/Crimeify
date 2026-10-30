@extends('layouts.app')

@use('Modules\Game\Entities\LiveGame')

@section('title', 'Game scores')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('modules/game/scores.css') }}" />
@endpush

{{-- The games' scores, for administrators only: hosting a live game, of either game; for the game chosen, each
     player's best game between the dates chosen, best first, to choose the winner from; then the winners chosen so far,
     from every game; then the live games. The games themselves are public, on the Games page (/public/game). --}}
@section('content')
<div class="page-head">
    <div>
        <h1>Game scores</h1>
        <p class="muted">
            The games, which anyone can play without logging in on the Games page, <a href="{{ route('public.game') }}">{{ route('public.game') }}</a>.
            Only administrators see the scores.
        </p>
    </div>
    <a class="btn btn-primary" href="{{ route('public.game') }}" target="_blank" rel="noopener">
        <span class="material-icon btn-icon" aria-hidden="true">sports_esports</span>Open the Games page
    </a>
</div>

{{-- A live game, for a room: everyone joins with its code on their phone, and the big screen runs it. --}}
<section class="card scores-board scores-live" aria-labelledby="host-heading">
    <div>
        <h2 id="host-heading">Host a live game</h2>
        <p class="muted">
            Like a quiz night: put its big screen on a projector or share it in a meeting, and everyone joins with its
            code on their phone. Each round shows everyone the same two cards, or the same district to pin on the map,
            and the sooner the answer, the more points. Its leaderboard shows at the end.
        </p>
    </div>
    <form method="post" action="{{ page_url('game-scores/store-live') }}" class="scores-filters scores-live-form"
        data-confirm="Are you sure you want to host a live game now?" data-confirm-kind="save">
        @csrf
        <div>
            <label for="live-game">Game</label>
            <select id="live-game" name="game">
                @foreach ($liveKinds as $key => $name)
                    <option value="{{ $key }}" @selected($key === old('game', $liveKind))>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        {{-- DistrictGuessr's level for everyone: Easy zooms each phone's map into the district's state. --}}
        <div>
            <label for="live-difficulty">Level <span class="scores-level-note">(DistrictGuessr)</span></label>
            <select id="live-difficulty" name="difficulty">
                <option value="easy" @selected(old('difficulty', $liveDifficulty) === 'easy')>Easy</option>
                <option value="hard" @selected(old('difficulty', $liveDifficulty) === 'hard')>Hard</option>
            </select>
        </div>
        <div>
            <label for="live-rounds">Rounds</label>
            <select id="live-rounds" name="rounds">
                @foreach (range($rounds['least'], $rounds['most']) as $count)
                    <option value="{{ $count }}" @selected($count === (int) old('rounds', $rounds['default']))>{{ $count }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">
            <span class="material-icon btn-icon" aria-hidden="true">groups</span>Host a live game
        </button>
    </form>
</section>

<section class="card scores-board" aria-labelledby="leaderboard-heading">
    {{-- Each game's leaderboard, one at a time, keeping the dates chosen. --}}
    <nav class="scores-games" aria-label="Games">
        @foreach ($games as $key => $each)
            @php $current = $key === $game; @endphp
            <a href="{{ page_url('game-scores', array_filter(['game' => $key === array_key_first($games) ? null : $key, 'from' => $from, 'to' => $to])) }}"
                @class(['scores-game', 'is-current' => $current]) aria-current="{{ $current ? 'page' : 'false' }}">{{ $each['name'] }}</a>
        @endforeach
    </nav>

    <form method="get" action="{{ page_url('game-scores') }}" class="scores-filters">
        @if ($game !== array_key_first($games))
            <input type="hidden" name="game" value="{{ $game }}" />
        @endif
        <div>
            <label for="scores-from">Games from</label>
            <input type="date" id="scores-from" name="from" value="{{ $from }}" />
        </div>
        <div>
            <label for="scores-to">To</label>
            <input type="date" id="scores-to" name="to" value="{{ $to }}" />
        </div>
        <div class="scores-filter-actions">
            <button type="submit" class="btn">Show</button>
            @if ($filtered)
                <a class="btn btn-ghost" href="{{ page_url('game-scores', $game === array_key_first($games) ? [] : ['game' => $game]) }}">Clear</a>
            @endif
        </div>
    </form>

    <h2 id="leaderboard-heading">{{ $gameName }} leaderboard <span class="scores-period">{{ $periodText }}</span></h2>

    @if ($players->isEmpty())
        <p class="scores-empty muted">
            @if ($filtered)
                No games between these dates.
            @else
                No games of {{ $gameName }} yet. Share the Games page, <a href="{{ route('public.game') }}">{{ route('public.game') }}</a>, with your colleagues to start.
            @endif
        </p>
    @else
        <p class="scores-count muted">
            {{ $players->count() }} {{ Str::plural('player', $players->count()) }}, {{ $gameCount }} {{ Str::plural('game', $gameCount) }}.
            Each player's best game; of the same score, whoever reached it first ranks higher.
        </p>
        <div class="table-wrap">
            <table class="table scores-table">
                <thead>
                    <tr>
                        <th class="num">Rank</th>
                        <th>Player</th>
                        <th>Best score</th>
                        <th>Games</th>
                        <th>Time to reach it</th>
                        <th>When</th>
                        <th class="actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($players as $player)
                        @php $run = $player['run']; @endphp
                        <tr @class(['scores-top' => $player['rank'] <= 3 && $run->Score > 0])>
                            <td class="num"><span class="scores-rank">{{ $player['rank'] }}</span></td>
                            <td>
                                {{ $run->PlayerName }}
                                @if ($winningRuns->has($run->Id))
                                    <span class="badge scores-winner"><span class="material-icon" aria-hidden="true">emoji_events</span>Winner</span>
                                @endif
                            </td>
                            <td class="scores-score">{{ $run->Score }}</td>
                            <td>{{ $player['games'] }}</td>
                            <td class="nowrap">{{ $player['time'] ?? '—' }}</td>
                            <td class="nowrap muted">{{ ($run->ScoredAt ?? $run->StartedAt)->format('d M Y, H:i') }}</td>
                            <td class="actions">
                                @unless ($winningRuns->has($run->Id))
                                    <form method="post" action="{{ page_url('game-scores/store-winner') }}"
                                        data-confirm="Are you sure you want to choose {{ $run->PlayerName }} as the winner, with a score of {{ $run->Score }}?" data-confirm-kind="save">
                                        @csrf
                                        <input type="hidden" name="game" value="{{ $game }}" />
                                        <input type="hidden" name="run" value="{{ $run->Id }}" />
                                        @if ($from)<input type="hidden" name="from" value="{{ $from }}" />@endif
                                        @if ($to)<input type="hidden" name="to" value="{{ $to }}" />@endif
                                        <button type="submit" class="btn btn-sm btn-success">Choose as winner</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="card scores-board" aria-labelledby="winners-heading">
    <h2 id="winners-heading">Winners</h2>
    @if ($winners->isEmpty())
        <p class="scores-empty muted">None chosen yet. Choose one from the leaderboard above.</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Player</th>
                        <th>Game</th>
                        <th>Score</th>
                        <th>From the games</th>
                        <th>Chosen by</th>
                        <th>When</th>
                        <th class="actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($winners as $winner)
                        <tr>
                            <td><span class="material-icon scores-trophy" aria-hidden="true">emoji_events</span>{{ $winner->PlayerName }}</td>
                            <td class="nowrap">{{ $games[$winner->Game]['name'] ?? $winner->Game }}</td>
                            <td class="scores-score">{{ $winner->Score }}</td>
                            <td>{{ $winner->period() }}</td>
                            <td>{{ $winner->ChosenByName }}</td>
                            <td class="nowrap muted">{{ $winner->ChosenAt->format('d M Y, H:i') }}</td>
                            <td class="actions">
                                <form method="post" action="{{ page_url('game-scores/destroy-winner', ['winner' => $winner]) }}"
                                    data-confirm="Are you sure you want to take {{ $winner->PlayerName }} off the winners?" data-confirm-kind="delete">
                                    @csrf
                                    @method('DELETE')
                                    @if ($game !== array_key_first($games))<input type="hidden" name="game" value="{{ $game }}" />@endif
                                    @if ($from)<input type="hidden" name="from" value="{{ $from }}" />@endif
                                    @if ($to)<input type="hidden" name="to" value="{{ $to }}" />@endif
                                    <button type="submit" class="btn btn-sm btn-danger-ghost">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- The newest live games, each with where it's up to and its winner, and its big screen: after the game, its final
     leaderboard. --}}
<section class="card scores-board" aria-labelledby="live-games-heading">
    <h2 id="live-games-heading">Live games</h2>
    @if ($liveGames->isEmpty())
        <p class="scores-empty muted">None yet. Host one above.</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Game</th>
                        <th>Hosted</th>
                        <th>Rounds</th>
                        <th>Players</th>
                        <th>Winner</th>
                        <th class="actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($liveGames as $live)
                        @php $top = $live->Status === LiveGame::Finished ? $live->players->first() : null; @endphp
                        <tr>
                            <td class="nowrap scores-score">{{ $live->spacedCode() }}</td>
                            <td class="nowrap">{{ $live->gameName() }}</td>
                            <td class="nowrap muted">{{ $live->CreatedAt->format('d M Y, H:i') }} <span class="scores-host">by {{ $live->HostName }}</span></td>
                            <td>
                                @if ($live->Status === LiveGame::Lobby)
                                    {{ $live->Rounds }}, not started
                                @elseif ($live->Status === LiveGame::Playing)
                                    Round {{ $live->Round }} of {{ $live->Rounds }}
                                @else
                                    {{ $live->Rounds }}
                                @endif
                            </td>
                            <td>{{ $live->players->count() }}</td>
                            <td>
                                @if ($top)
                                    <span class="material-icon scores-trophy" aria-hidden="true">emoji_events</span>{{ $top->PlayerName }}
                                    <span class="muted">· {{ number_format($top->Score) }} points</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="actions">
                                <a class="btn btn-sm" href="{{ page_url('game-scores/live', ['liveGame' => $live]) }}">
                                    {{ $live->Status === LiveGame::Finished ? 'Results' : 'Big screen' }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
