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
use Modules\Game\Entities\SkylineRun;
use Modules\Game\Support\Skyline;

// Skyline, for everyone without logging in (/public/game/skyline): each round a state's police districts as 3D towers,
// each as tall as its count of one crime in one year, one of them greyed out to raise to the height thought right, ten
// times. The page draws them (skyline.js, skyline-scene.js); start(), guess() and next() play it, and the server keeps
// the rounds and the score, so the count to guess never reaches the browser before its guess, and the score can't be
// made up. Only administrators see the scores, on the Game scores page.
class SkylineController extends Controller
{
    public function __construct(private Skyline $skyline) {}

    /**
     * The game's page, in the public pages' layout.
     */
    public function page(): View
    {
        $config = config('game.skyline');
        $language = app()->getLocale() === SetPublicLocale::defaultLocale() ? [] : ['lang' => app()->getLocale()];

        return view('game::skyline', [
            'ready' => $this->skyline->ready(),
            'rounds' => $config['rounds'],
            'seconds' => $config['seconds'],
            'points' => $config['points'],
            // As a percentage.
            'perfect' => $config['perfect'] * 100,
            'nameLength' => config('game.name_length'),
            'boundaries' => versioned_asset(config('map.boundaries')),
            // In the page's language, even where cookies aren't kept.
            'urls' => [
                'start' => route('public.game.skyline.start', $language),
                'guess' => route('public.game.skyline.guess', $language),
                'next' => route('public.game.skyline.next', $language),
            ],
        ]);
    }

    /**
     * Start a game for the name given: its first round, to guess once the countdown is over, when its time starts.
     */
    public function start(Request $request): JsonResponse
    {
        $name = GameRun::validName($request);
        $dealt = $this->skyline->deal() ?? abort(503, __('There are no crime figures to play with yet.'));

        $run = SkylineRun::create([
            'Token' => Str::random(48),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Score' => 0,
            'Dealt' => $dealt,
            'Guesses' => [],
            // Asked once the countdown is over, so it doesn't take from the time to guess.
            'AskedAt' => now()->addSeconds(config('game.skyline.ready')),
            'StartedAt' => now(),
        ]);

        return response()->json([
            'token' => $run->Token,
            'rounds' => config('game.skyline.rounds'),
            'seconds' => config('game.skyline.seconds'),
            'ready' => config('game.skyline.ready'),
            'asked' => $this->skyline->asked($run),
        ]);
    }

    /**
     * A guess at the hidden tower's count, or none when the time ran out: its points, the count, and how far off it was.
     * Later than config('game.skyline.seconds') and a few more for it to arrive counts as none. After the last round,
     * the game is over, with every round's.
     */
    public function guess(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'guess' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        // One guess at a time for each game, so the same guess sent twice counts once.
        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_if($run->AskedAt === null, 409, __('This round has had its guess. Go on to the next one.'));

            $config = config('game.skyline');
            $round = $run->Dealt[$run->round() - 1];
            $late = $request->input('guess') === null || now()->greaterThan($run->AskedAt->copy()->addSeconds($config['seconds'] + $config['grace']));
            $guess = $late ? null : min($request->integer('guess'), Skyline::most($round));
            $points = Skyline::points($guess, $round['crimes']);

            $guesses = $run->Guesses;
            $guesses[] = ['guess' => $guess, 'points' => $points];
            $over = count($guesses) >= count($run->Dealt);

            $run->update([
                'Guesses' => $guesses,
                'Score' => $run->Score + $points,
                'ScoredAt' => $points > 0 ? now() : $run->ScoredAt,
                'AskedAt' => null,
                'EndedAt' => $over ? now() : null,
            ]);

            return response()->json([
                'round' => count($guesses),
                'late' => $late,
                'guess' => $guess,
                'crimes' => $round['crimes'],
                'points' => $points,
                'score' => $run->Score,
                'over' => $over,
                // At the end, every round's district, its count, the guess and its points.
                'rounds' => $over ? array_map(fn (array $asked, array $guess) => [
                    'district' => $asked['target'], 'state' => $asked['state'], 'crimes' => $asked['crimes'], 'guess' => $guess['guess'], 'points' => $guess['points'],
                ], $run->Dealt, $guesses) : null,
            ]);
        });
    }

    /**
     * The next round, after the last one's guess and answer, its time starting now.
     */
    public function next(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:64']]);

        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_unless($run->AskedAt === null, 409, __('This round is still waiting for its guess.'));

            $run->update(['AskedAt' => now()]);

            return response()->json(['asked' => $this->skyline->asked($run)]);
        });
    }

    /**
     * The game the token is for, locked until the request is done, and not over.
     */
    private function run(Request $request): SkylineRun
    {
        $run = SkylineRun::query()->where('Token', $request->input('token'))->lockForUpdate()->first()
            ?? abort(404, __("This game wasn't found. Start a new one."));
        abort_if($run->EndedAt !== null, 409, __('This game has ended. Start a new one.'));

        return $run;
    }
}
