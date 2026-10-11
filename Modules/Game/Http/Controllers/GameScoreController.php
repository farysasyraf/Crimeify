<?php

namespace Modules\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\Game\Entities\GameRun;
use Modules\Game\Entities\GameWinner;
use Modules\Game\Entities\LiveGame;

// The Game scores page, where administrators see how everyone did at Higher or Lower and choose the winner: each
// player's best score from the games played between the dates chosen, best first, and the winners chosen so far. A
// player is everyone who gave the same name, whatever its capitals. Live games are hosted from here too (Host a live
// game, LiveGameController), and listed under the winners. Its routes are on the Routes page, for ADMIN to start with:
// the game is public, but the solo game's scores aren't.
class GameScoreController extends Controller
{
    /**
     * The leaderboard: each player's best game, the highest score first, and of the same score, the one that reached
     * it first; how many games they played; and the winners chosen so far, newest first.
     */
    public function index(Request $request): View
    {
        $period = $this->period($request);
        $games = $this->games($period);

        $played = (clone $games)->groupBy('PlayerKey')->selectRaw('PlayerKey, COUNT(*) AS Games')->pluck('Games', 'PlayerKey');
        $best = (clone $games)
            ->orderByDesc('Score')->orderBy('ScoredAt')->orderBy('Id')
            ->get(['Id', 'PlayerName', 'PlayerKey', 'Score', 'StartedAt', 'ScoredAt'])
            ->unique('PlayerKey')
            ->values();

        $winners = GameWinner::query()->orderByDesc('ChosenAt')->orderByDesc('Id')->get();

        return view('game::scores', [
            'from' => $period['from'],
            'to' => $period['to'],
            'filtered' => $period['from'] !== null || $period['to'] !== null,
            'periodText' => GameWinner::describePeriod($period['from'], $period['to']),
            'players' => $best->map(fn (GameRun $run, int $index) => [
                'rank' => $index + 1,
                'run' => $run,
                'games' => (int) ($played[$run->PlayerKey] ?? 1),
                'time' => $this->duration($run->secondsToScore()),
            ]),
            'gameCount' => (int) $played->sum(),
            'winners' => $winners,
            'winningRuns' => $winners->pluck('GameRunId')->filter()->flip(),
            'rounds' => config('game.live.rounds'),
            // The newest live games, each with its players, most points first, for its winner.
            'liveGames' => LiveGame::query()
                ->with(['players' => fn ($query) => $query->orderByDesc('Score')->orderBy('PlayerName')])
                ->orderByDesc('Id')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Choose a player as the winner, with their best game from the dates the leaderboard was showing.
     */
    public function storeWinner(Request $request): RedirectResponse
    {
        $request->validate([
            'run' => ['required', 'integer', 'exists:GameRuns,Id'],
        ]);

        $period = $this->period($request);
        $run = GameRun::findOrFail($request->integer('run'));

        GameWinner::create([
            'GameRunId' => $run->Id,
            'PlayerName' => $run->PlayerName,
            'Score' => $run->Score,
            'FromDate' => $period['from'],
            'ToDate' => $period['to'],
            'ChosenById' => $request->user()->Id,
            'ChosenByName' => $request->user()->Name,
            'ChosenAt' => now(),
        ]);

        return redirect(page_url('game-scores', array_filter($period)))
            ->with('message', "Chose {$run->PlayerName} as the winner, with a score of {$run->Score}.");
    }

    /**
     * Take a player off the winners, as when they were chosen by mistake. Their games stay as they are.
     */
    public function destroyWinner(Request $request, GameWinner $winner): RedirectResponse
    {
        $winner->delete();

        return redirect(page_url('game-scores', array_filter($this->period($request))))
            ->with('message', "Took {$winner->PlayerName} off the winners.");
    }

    /**
     * The dates chosen, as Y-m-d, either of them none: ?from= and ?to=, each a day, ignored if it isn't one. Swapped if
     * the first is after the second.
     *
     * @return array{from: ?string, to: ?string}
     */
    private function period(Request $request): array
    {
        $day = fn (mixed $value): ?string => is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;

        [$from, $to] = [$day($request->input('from')), $day($request->input('to'))];

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * The games started between the dates, in Malaysia time as the app keeps them, both days included.
     *
     * @param  array{from: ?string, to: ?string}  $period
     * @return Builder<GameRun>
     */
    private function games(array $period): Builder
    {
        return GameRun::query()
            ->when($period['from'], fn ($query, string $from) => $query->where('StartedAt', '>=', Carbon::parse($from)->startOfDay()))
            ->when($period['to'], fn ($query, string $to) => $query->where('StartedAt', '<', Carbon::parse($to)->addDay()->startOfDay()));
    }

    /**
     * How long a game took to reach its score, like "45 s" or "2 min 5 s"; none before the first right answer.
     */
    private function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return $seconds < 60 ? "{$seconds} s" : intdiv($seconds, 60).' min'.($seconds % 60 > 0 ? ' '.($seconds % 60).' s' : '');
    }
}
