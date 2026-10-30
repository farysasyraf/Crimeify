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
use Modules\Game\Entities\GuessrRun;
use Modules\Game\Support\DistrictGuessr;

// DistrictGuessr, for everyone without logging in (/public/game/districtguessr): a police district's name and its state,
// to find on a map of Malaysia with only its states' outlines, ten times, on Easy (the map zooms into the state) or
// Hard (the whole of Malaysia). The page draws the game (districtguessr.js);
// start(), guess() and next() play it, and the server keeps the districts and the score, so where a district is never
// reaches the browser before its guess, and the score can't be made up. Only administrators see the scores, on the Game
// scores page.
class DistrictGuessrController extends Controller
{
    public function __construct(private DistrictGuessr $guessr) {}

    /**
     * The game's page, in the public pages' layout.
     */
    public function page(): View
    {
        $config = config('game.districtguessr');
        $language = app()->getLocale() === SetPublicLocale::defaultLocale() ? [] : ['lang' => app()->getLocale()];

        return view('game::districtguessr', [
            'ready' => $this->guessr->ready(),
            'rounds' => $config['rounds'],
            'seconds' => $config['seconds'],
            'points' => $config['points'],
            'perfect' => $config['perfect'],
            'nameLength' => config('game.name_length'),
            'boundaries' => versioned_asset(config('map.boundaries')),
            // In the page's language, even where cookies aren't kept.
            'urls' => [
                'start' => route('public.game.districtguessr.start', $language),
                'guess' => route('public.game.districtguessr.guess', $language),
                'next' => route('public.game.districtguessr.next', $language),
            ],
        ]);
    }

    /**
     * Start a game for the name given, at the level chosen (Hard if none): its first district, to find once the
     * countdown is over, when its time starts.
     */
    public function start(Request $request): JsonResponse
    {
        $name = GameRun::validName($request);
        $request->validate(['difficulty' => ['nullable', 'in:'.implode(',', DistrictGuessr::Levels)]]);
        $districts = $this->guessr->deal() ?? abort(503, __('There are no police districts to play with yet.'));

        $run = GuessrRun::create([
            'Token' => Str::random(48),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Difficulty' => $request->input('difficulty') ?? DistrictGuessr::Hard,
            'Score' => 0,
            'Districts' => $districts,
            'Guesses' => [],
            // Asked once the countdown is over, so it doesn't take from the time to guess.
            'AskedAt' => now()->addSeconds(config('game.districtguessr.ready')),
            'StartedAt' => now(),
        ]);

        return response()->json([
            'token' => $run->Token,
            'difficulty' => $run->Difficulty,
            'rounds' => config('game.districtguessr.rounds'),
            'seconds' => config('game.districtguessr.seconds'),
            'ready' => config('game.districtguessr.ready'),
            'district' => $this->guessr->asked($run),
        ]);
    }

    /**
     * A guess at where the district being asked is, or none when the time ran out: how far off it was, its points, and
     * where the district is. Later than config('game.districtguessr.seconds') and a few more for it to arrive counts as
     * none. After the last district, the game is over, with every round's.
     */
    public function guess(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        // One guess at a time for each game, so the same guess sent twice counts once.
        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_if($run->AskedAt === null, 409, __('This district has had its guess. Go on to the next one.'));

            $config = config('game.districtguessr');
            $district = $run->Districts[$run->round() - 1];
            $late = $request->input('lat') === null || now()->greaterThan($run->AskedAt->copy()->addSeconds($config['seconds'] + $config['grace']));
            $km = $late ? null : DistrictGuessr::km((float) $request->input('lat'), (float) $request->input('lng'), $district['lat'], $district['lng']);
            $points = DistrictGuessr::points($km);

            $guesses = $run->Guesses;
            $guesses[] = [
                'lat' => $late ? null : (float) $request->input('lat'),
                'lng' => $late ? null : (float) $request->input('lng'),
                'km' => $km === null ? null : round($km, 1),
                'points' => $points,
            ];
            $over = count($guesses) >= $config['rounds'];

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
                'km' => $km === null ? null : round($km, 1),
                'points' => $points,
                'score' => $run->Score,
                'answer' => ['lat' => $district['lat'], 'lng' => $district['lng']],
                'over' => $over,
                // At the end, every round's district, how far off it was and its points.
                'rounds' => $over ? array_map(fn (array $asked, array $guess) => [
                    'name' => $asked['name'], 'place' => $asked['place'], 'km' => $guess['km'], 'points' => $guess['points'],
                ], $run->Districts, $guesses) : null,
            ]);
        });
    }

    /**
     * The next district, after the last one's guess and answer, its time starting now.
     */
    public function next(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:64']]);

        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_unless($run->AskedAt === null, 409, __('This district is still waiting for its guess.'));

            $run->update(['AskedAt' => now()]);

            return response()->json(['district' => $this->guessr->asked($run)]);
        });
    }

    /**
     * The game the token is for, locked until the request is done, and not over.
     */
    private function run(Request $request): GuessrRun
    {
        $run = GuessrRun::query()->where('Token', $request->input('token'))->lockForUpdate()->first()
            ?? abort(404, __("This game wasn't found. Start a new one."));
        abort_if($run->EndedAt !== null, 409, __('This game has ended. Start a new one.'));

        return $run;
    }
}
