<?php

namespace Modules\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetPublicLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Game\Entities\CaseRun;
use Modules\Game\Entities\GameRun;
use Modules\Game\Support\CaseFile;

// Case File, for everyone without logging in (/public/game/case-file): each case a mystery police district, to pick out
// from six suspects on the corkboard by the clues on a detective's desk, the fewer opened the more points, five
// times. The page draws the office (case-file.js, case-desk.js); start(), clue(), accuse() and next() play it, and
// the server keeps the cases and the score, so which suspect it is never reaches the browser before its pick, a clue
// only once it's opened, and the score can't be made up. Only administrators see the scores, on the Game scores page.
class CaseFileController extends Controller
{
    public function __construct(private CaseFile $cases) {}

    /**
     * The game's page, in the public pages' layout.
     */
    public function page(): View
    {
        $config = config('game.case_file');
        $language = app()->getLocale() === SetPublicLocale::defaultLocale() ? [] : ['lang' => app()->getLocale()];

        return view('game::case-file', [
            'ready' => $this->cases->ready(),
            'cases' => $config['cases'],
            'seconds' => $config['seconds'],
            'points' => $config['points'],
            'costs' => $config['clues'],
            'least' => CaseFile::worth(CaseFile::order()),
            'suspects' => $config['suspects'],
            'nameLength' => config('game.name_length'),
            'boundaries' => versioned_asset(config('map.boundaries')),
            // In the page's language, even where cookies aren't kept.
            'urls' => [
                'start' => route('public.game.case-file.start', $language),
                'clue' => route('public.game.case-file.clue', $language),
                'accuse' => route('public.game.case-file.accuse', $language),
                'next' => route('public.game.case-file.next', $language),
            ],
        ]);
    }

    /**
     * Start a game for the name given: its first case, to solve once the countdown is over, when its time starts.
     */
    public function start(Request $request): JsonResponse
    {
        $name = GameRun::validName($request);
        $dealt = $this->cases->deal() ?? abort(503, __('There are no crime figures to play with yet.'));

        $run = CaseRun::create([
            'Token' => Str::random(48),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Score' => 0,
            'Dealt' => $dealt,
            'Opened' => [CaseFile::order()[0]],
            'Picks' => [],
            // Asked once the countdown is over, so it doesn't take from the time to solve it.
            'AskedAt' => now()->addSeconds(config('game.case_file.ready')),
            'StartedAt' => now(),
        ]);

        return response()->json([
            'token' => $run->Token,
            'cases' => config('game.case_file.cases'),
            'seconds' => config('game.case_file.seconds'),
            'ready' => config('game.case_file.ready'),
            'asked' => $this->asked($run),
        ]);
    }

    /**
     * Open a clue in the case being played: what it says, and what the case is worth now.
     */
    public function clue(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'clue' => ['required', 'string', Rule::in(CaseFile::order())],
        ]);

        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_if($run->AskedAt === null, 409, __('This case is closed. Go on to the next one.'));

            $clue = $request->input('clue');
            $opened = array_values(array_unique([...$run->Opened, $clue]));
            $run->update(['Opened' => $opened]);

            return response()->json([
                'clue' => $clue,
                'said' => CaseFile::clue($run->Dealt[$run->round() - 1], $clue),
                'worth' => CaseFile::worth($opened),
            ]);
        });
    }

    /**
     * Pick a suspect for the case being played, by their place on the board, or none when the time ran out: whether
     * it was them, the points, and every clue, to see what it was. Later than config('game.case_file.seconds') and a
     * few more for it to arrive counts as none. After the last case, the game is over, with every case's.
     */
    public function accuse(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'suspect' => ['nullable', 'integer', 'min:0', 'max:'.(config('game.case_file.suspects') - 1)],
        ]);

        // One pick at a time for each game, so the same pick sent twice counts once.
        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_if($run->AskedAt === null, 409, __('This case is closed. Go on to the next one.'));

            $config = config('game.case_file');
            $case = $run->Dealt[$run->round() - 1];
            $late = $request->input('suspect') === null || now()->greaterThan($run->AskedAt->copy()->addSeconds($config['seconds'] + $config['grace']));
            $suspect = $late ? null : $request->integer('suspect');
            $right = $suspect === $case['culprit'];
            $points = $right ? CaseFile::worth($run->Opened) : 0;

            $picks = $run->Picks;
            $picks[] = ['suspect' => $suspect, 'right' => $right, 'clues' => count($run->Opened), 'points' => $points];
            $over = count($picks) >= count($run->Dealt);

            $run->update([
                'Picks' => $picks,
                'Score' => $run->Score + $points,
                'ScoredAt' => $points > 0 ? now() : $run->ScoredAt,
                'AskedAt' => null,
                'EndedAt' => $over ? now() : null,
            ]);

            return response()->json([
                'case' => count($picks),
                'late' => $late,
                'suspect' => $suspect,
                'culprit' => $case['culprit'],
                'right' => $right,
                'points' => $points,
                'score' => $run->Score,
                'clues' => CaseFile::clues($case, CaseFile::order()),
                'over' => $over,
                // At the end, every case's district, its state, whether it was solved, the clues it took and its points.
                'summary' => $over ? array_map(fn (array $asked, array $pick) => [
                    'district' => $asked['name'], 'state' => $asked['state'], 'right' => $pick['right'], 'clues' => $pick['clues'], 'points' => $pick['points'],
                ], $run->Dealt, $picks) : null,
            ]);
        });
    }

    /**
     * The next case, after the last one's pick and answer, its time starting now.
     */
    public function next(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:64']]);

        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_unless($run->AskedAt === null, 409, __('This case is still waiting for its pick.'));

            $run->update(['AskedAt' => now(), 'Opened' => [CaseFile::order()[0]]]);

            return response()->json(['asked' => $this->asked($run)]);
        });
    }

    /**
     * What the page shows of the case being asked: its number, its suspects, the clues open, and what it's worth.
     *
     * @return array<string, mixed>
     */
    private function asked(CaseRun $run): array
    {
        $case = $run->Dealt[$run->round() - 1];

        return [
            'case' => $run->round(),
            'suspects' => CaseFile::suspects($case),
            'clues' => CaseFile::clues($case, $run->Opened),
            'worth' => CaseFile::worth($run->Opened),
        ];
    }

    /**
     * The game the token is for, locked until the request is done, and not over.
     */
    private function run(Request $request): CaseRun
    {
        $run = CaseRun::query()->where('Token', $request->input('token'))->lockForUpdate()->first()
            ?? abort(404, __("This game wasn't found. Start a new one."));
        abort_if($run->EndedAt !== null, 409, __('This game has ended. Start a new one.'));

        return $run;
    }
}
