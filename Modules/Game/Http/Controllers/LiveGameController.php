<?php

namespace Modules\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Game\Entities\LiveGame;
use Modules\Game\Support\LiveGames;

// Hosting a live game of Higher or Lower (LiveGames): made from the Game scores page with the number of rounds, then
// run from its big screen, for everyone in the room to see, which shows the code to join with, then each round's cards
// and answer, and at the end the leaderboard. The screen checks the game about every second (state()) and its buttons
// move it on. Its routes are on the Routes page, with Game scores', for ADMIN to start with.
class LiveGameController extends Controller
{
    public function __construct(private LiveGames $live) {}

    /**
     * Make a game with the rounds chosen, and open its big screen.
     */
    public function store(Request $request): RedirectResponse
    {
        $rounds = config('game.live.rounds');
        $request->validate(['rounds' => ['required', 'integer', "between:{$rounds['least']},{$rounds['most']}"]], [
            'rounds.between' => 'A live game can have :min to :max rounds.',
        ]);

        $game = $this->live->create($request->user(), $request->integer('rounds'));

        if ($game === null) {
            return redirect(page_url('game-scores'))
                ->with('message', 'There are no crime figures to play with yet. Load them with php artisan map:import-crime.')
                ->with('status', 'error');
        }

        return redirect(page_url('game-scores/live', ['liveGame' => $game]));
    }

    /**
     * The game's big screen. After the game, its final leaderboard.
     */
    public function host(LiveGame $liveGame): View
    {
        return view('game::live-host', [
            'game' => $liveGame,
            'joinUrl' => route('public.game.live'),
            'urls' => [
                'state' => page_url('game-scores/live-state', ['liveGame' => $liveGame]),
                'next' => page_url('game-scores/live-next', ['liveGame' => $liveGame]),
                'close' => page_url('game-scores/live-close', ['liveGame' => $liveGame]),
                'finish' => page_url('game-scores/live-finish', ['liveGame' => $liveGame]),
            ],
        ]);
    }

    /**
     * Where the game is up to, for the big screen, as JSON.
     */
    public function state(LiveGame $liveGame): JsonResponse
    {
        return $this->view($liveGame);
    }

    /**
     * Start the game, or after a round's answer, the next round, or after the last, the end.
     */
    public function next(LiveGame $liveGame): JsonResponse
    {
        $this->live->next($liveGame);

        return $this->view($liveGame);
    }

    /**
     * Show the round's answer now, without waiting for the time to run out.
     */
    public function close(LiveGame $liveGame): JsonResponse
    {
        $this->live->close($liveGame);

        return $this->view($liveGame);
    }

    /**
     * End the game now, with the scores so far as its leaderboard.
     */
    public function finish(LiveGame $liveGame): JsonResponse
    {
        $this->live->finish($liveGame);

        return $this->view($liveGame);
    }

    private function view(LiveGame $game): JsonResponse
    {
        return response()->json($this->live->forHost($this->live->state($game->Id) ?? abort(404)));
    }
}
