<?php

namespace Modules\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Game\Entities\GameRun;
use Modules\Game\Entities\GameWinner;
use Modules\Game\Entities\GuessrRun;
use Modules\Game\Entities\LiveGame;

// The Game scores page, where administrators see how everyone did at the games and choose the winner: for the game
// chosen (?game=, Higher or Lower to start with), each player's best score from the games played between the dates
// chosen, best first; then the winners chosen so far, from every game. A player is everyone who gave the same name,
// whatever its capitals. Live games of Higher or Lower are hosted from here too (Host a live game, LiveGameController),
// and listed under the winners. Its routes are on the Routes page, for ADMIN to start with: the games are public, but
// their scores aren't.
class GameScoreController extends Controller
{
    /**
     * The games with scores here, by their key on the Games page: their name, and the model of their games, each with
     * PlayerName, PlayerKey, Score, StartedAt and ScoredAt (when it last scored), and secondsToScore().
     *
     * @var array<string, array{name: string, model: class-string<GameRun|GuessrRun>}>
     */
    public const Games = [
        'higher-or-lower' => ['name' => 'Higher or Lower', 'model' => GameRun::class],
        'districtguessr' => ['name' => 'DistrictGuessr', 'model' => GuessrRun::class],
    ];

    /**
     * The leaderboard: each player's best game, the highest score first, and of the same score, the one that reached
     * it first; how many games they played; and the winners chosen so far, newest first.
     */
    public function index(Request $request): View
    {
        $game = $this->game($request);
        $period = $this->period($request);
        $games = $this->games($game, $period);

        $played = (clone $games)->groupBy('PlayerKey')->selectRaw('PlayerKey, COUNT(*) AS Games')->pluck('Games', 'PlayerKey');
        $best = (clone $games)
            ->orderByDesc('Score')->orderBy('ScoredAt')->orderBy('Id')
            ->get(['Id', 'PlayerName', 'PlayerKey', 'Score', 'StartedAt', 'ScoredAt'])
            ->unique('PlayerKey')
            ->values();

        $winners = GameWinner::query()->orderByDesc('ChosenAt')->orderByDesc('Id')->get();

        return view('game::scores', [
            'game' => $game,
            'gameName' => self::Games[$game]['name'],
            'games' => self::Games,
            'from' => $period['from'],
            'to' => $period['to'],
            'filtered' => $period['from'] !== null || $period['to'] !== null,
            'periodText' => GameWinner::describePeriod($period['from'], $period['to']),
            'players' => $best->map(fn (GameRun|GuessrRun $run, int $index) => [
                'rank' => $index + 1,
                'run' => $run,
                'games' => (int) ($played[$run->PlayerKey] ?? 1),
                'time' => $this->duration($run->secondsToScore()),
            ]),
            'gameCount' => (int) $played->sum(),
            'winners' => $winners,
            // This game's winning games, by Id.
            'winningRuns' => $winners->where('Game', $game)->pluck('GameRunId')->filter()->flip(),
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
     * Choose a player as the winner, with their best game of the game chosen, from the dates the leaderboard was showing.
     */
    public function storeWinner(Request $request): RedirectResponse
    {
        $game = $this->game($request);
        $model = self::Games[$game]['model'];
        $request->validate([
            'run' => ['required', 'integer', Rule::exists((new $model)->getTable(), 'Id')],
        ]);

        $period = $this->period($request);
        $run = $model::query()->findOrFail($request->integer('run'));

        GameWinner::create([
            'Game' => $game,
            'GameRunId' => $run->Id,
            'PlayerName' => $run->PlayerName,
            'Score' => $run->Score,
            'FromDate' => $period['from'],
            'ToDate' => $period['to'],
            'ChosenById' => $request->user()->Id,
            'ChosenByName' => $request->user()->Name,
            'ChosenAt' => now(),
        ]);

        return redirect(page_url('game-scores', $this->filters($game, $period)))
            ->with('message', "Chose {$run->PlayerName} as the winner, with a score of {$run->Score}.");
    }

    /**
     * Take a player off the winners, as when they were chosen by mistake. Their games stay as they are.
     */
    public function destroyWinner(Request $request, GameWinner $winner): RedirectResponse
    {
        $winner->delete();

        return redirect(page_url('game-scores', $this->filters($this->game($request), $this->period($request))))
            ->with('message', "Took {$winner->PlayerName} off the winners.");
    }

    /**
     * The game chosen: ?game=, one of Games, Higher or Lower if it isn't one.
     */
    private function game(Request $request): string
    {
        $game = $request->input('game');

        return is_string($game) && isset(self::Games[$game]) ? $game : array_key_first(self::Games);
    }

    /**
     * The address's choices for the leaderboard, as they were: the game, unless it's the first, and the dates.
     *
     * @param  array{from: ?string, to: ?string}  $period
     * @return array<string, string>
     */
    private function filters(string $game, array $period): array
    {
        return array_filter(['game' => $game === array_key_first(self::Games) ? null : $game] + $period);
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
     * The game's games started between the dates, in Malaysia time as the app keeps them, both days included.
     *
     * @param  array{from: ?string, to: ?string}  $period
     * @return Builder<GameRun>|Builder<GuessrRun>
     */
    private function games(string $game, array $period): Builder
    {
        return self::Games[$game]['model']::query()
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
