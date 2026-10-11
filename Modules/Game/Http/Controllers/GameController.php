<?php

namespace Modules\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetPublicLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Game\Entities\GameRun;
use Modules\Game\Support\Cards;

// Higher or Lower, for everyone without logging in (/public/game): a police district's count of a crime, then another
// district's count of the same crime to guess whether it's higher or lower, a new pair each round. The page draws the game (game.js); start() and guess() play it, and the
// server keeps the cards and the score, so the browser never has the count to guess before it's guessed, and the score
// can't be made up. Only administrators see the scores, on the Game scores page (GameScoreController).
class GameController extends Controller
{
    public function __construct(private Cards $cards) {}

    /**
     * The game's page, in the public pages' layout.
     */
    public function publicIndex(): View
    {
        return view('game::index', [
            'ready' => $this->cards->ready(),
            'seconds' => config('game.seconds'),
            'nameLength' => config('game.name_length'),
            // In the page's language, even where cookies aren't kept.
            'startUrl' => route('public.game.start', $this->language()),
            'guessUrl' => route('public.game.guess', $this->language()),
        ]);
    }

    /**
     * Start a game for the name given: the first round's two cards, the one shown with its count, and the one to guess
     * without.
     */
    public function start(Request $request): JsonResponse
    {
        $name = GameRun::validName($request);

        $cards = $this->cards->deal(0);
        abort_if($cards === null, 503, __('There are no crime figures to play with yet.'));
        [$first, $next] = $cards;

        $run = GameRun::create([
            'Token' => Str::random(48),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Score' => 0,
            'Current' => $first,
            'Next' => $next,
            'AskedAt' => now(),
            'StartedAt' => now(),
        ]);

        return response()->json([
            'token' => $run->Token,
            'score' => 0,
            'seconds' => config('game.seconds'),
            'shown' => $this->cards->face($first, withCount: true),
            'next' => $this->cards->face($next, withCount: false),
        ]);
    }

    /**
     * A guess at the hidden card's count: higher or lower than the one shown, or none when the time ran out. Right,
     * and the score goes up and the next round's two cards come, of a crime type chosen again. Wrong, or later than config('game.seconds') and a few more for
     * the answer to arrive, and the game ends. Either way, with the count that was hidden.
     */
    public function guess(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'guess' => ['nullable', 'in:higher,lower'],
        ]);

        // One guess at a time for each game, so the same right answer sent twice scores once.
        return DB::transaction(function () use ($request) {
            $run = GameRun::query()->where('Token', $request->input('token'))->lockForUpdate()->first()
                ?? abort(404, __("This game wasn't found. Start a new one."));
            abort_if($run->EndedAt !== null, 409, __('This game has ended. Start a new one.'));

            $hidden = $run->Next['crimes'];
            $deadline = $run->AskedAt->copy()->addSeconds(config('game.seconds') + config('game.grace'));
            $late = $request->input('guess') === null || now()->greaterThan($deadline);
            $right = ! $late && $request->input('guess') === ($hidden > $run->Current['crimes'] ? 'higher' : 'lower');
            $result = ['right' => $right, 'late' => $late, 'answer' => $hidden];

            if (! $right) {
                $run->update(['EndedAt' => now()]);

                return response()->json($result + ['score' => $run->Score, 'over' => true]);
            }

            $cards = $this->cards->deal($run->Score + 1);
            $run->update([
                'Score' => $run->Score + 1,
                'ScoredAt' => now(),
                'Current' => $cards[0] ?? $run->Current,
                'Next' => $cards[1] ?? $run->Next,
                'AskedAt' => now(),
                // Only if the figures went while the game was being played.
                'EndedAt' => $cards === null ? now() : null,
            ]);

            return response()->json($result + [
                'score' => $run->Score,
                'over' => $cards === null,
                'shown' => $cards === null ? null : $this->cards->face($cards[0], withCount: true),
                'next' => $cards === null ? null : $this->cards->face($cards[1], withCount: false),
            ]);
        });
    }

    /**
     * On the public pages, the page's language for the addresses they give, so the game's cards keep it, even where
     * cookies aren't kept. English, the default, needs none.
     *
     * @return array<string, string>
     */
    private function language(): array
    {
        return app()->getLocale() === SetPublicLocale::defaultLocale() ? [] : ['lang' => app()->getLocale()];
    }
}
