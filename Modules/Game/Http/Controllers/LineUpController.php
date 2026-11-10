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
use Modules\Game\Entities\LineUpRun;
use Modules\Game\Support\LineUp;
use Modules\Map\Support\CrimeData;

// Line-Up, for everyone without logging in (/public/game/line-up): each round five police districts of one state in a
// line-up, to put in order of their count of one crime in one year, most first, ten times. The page draws the room
// (line-up-room.js); start(), order() and next() play it, and the server keeps the rounds and the score, so the counts
// never reach the browser before its order, and the score can't be made up. Only administrators see the scores, on the
// Game scores page.
class LineUpController extends Controller
{
    public function __construct(private LineUp $lineUp) {}

    /**
     * The game's page, in the public pages' layout.
     */
    public function page(): View
    {
        $config = config('game.line_up');
        $language = app()->getLocale() === SetPublicLocale::defaultLocale() ? [] : ['lang' => app()->getLocale()];

        return view('game::line-up', [
            'ready' => $this->lineUp->ready(),
            'rounds' => $config['rounds'],
            'seconds' => $config['seconds'],
            'suspects' => $config['suspects'],
            'right' => $config['right'],
            'near' => $config['near'],
            'most' => LineUp::most(),
            'nameLength' => config('game.name_length'),
            'boundaries' => versioned_asset(config('map.boundaries')),
            // In the page's language, even where cookies aren't kept.
            'urls' => [
                'start' => route('public.game.line-up.start', $language),
                'order' => route('public.game.line-up.order', $language),
                'next' => route('public.game.line-up.next', $language),
            ],
        ]);
    }

    /**
     * Start a game for the name given: its first round, to order once the countdown is over, when its time starts.
     */
    public function start(Request $request): JsonResponse
    {
        $name = GameRun::validName($request);
        $dealt = $this->lineUp->deal() ?? abort(503, __('There are no crime figures to play with yet.'));

        $run = LineUpRun::create([
            'Token' => Str::random(48),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Score' => 0,
            'Dealt' => $dealt,
            'Orders' => [],
            // Asked once the countdown is over, so it doesn't take from the time to order them.
            'AskedAt' => now()->addSeconds(config('game.line_up.ready')),
            'StartedAt' => now(),
        ]);

        return response()->json([
            'token' => $run->Token,
            'rounds' => config('game.line_up.rounds'),
            'seconds' => config('game.line_up.seconds'),
            'ready' => config('game.line_up.ready'),
            'asked' => $this->lineUp->asked($run),
        ]);
    }

    /**
     * The order given, left to right, of the suspects by where they first stood; or none, when the time ran out before
     * any was moved. Its points, the right order, every suspect's count and how far off each stood. Later than
     * config('game.line_up.seconds') and a few more for it to arrive counts as none. After the last round, the game is
     * over, with every round's.
     */
    public function order(Request $request): JsonResponse
    {
        $suspects = config('game.line_up.suspects');
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'order' => ['nullable', 'array', "size:{$suspects}"],
            'order.*' => ['integer', 'distinct', 'min:0', 'max:'.($suspects - 1)],
        ]);

        // One order at a time for each game, so the same order sent twice counts once.
        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_if($run->AskedAt === null, 409, __('This round has had its line-up. Go on to the next one.'));

            $config = config('game.line_up');
            $round = $run->Dealt[$run->round() - 1];
            $given = $request->input('order');
            $late = $given === null || now()->greaterThan($run->AskedAt->copy()->addSeconds($config['seconds'] + $config['grace']));
            $order = $late ? null : array_map('intval', $given);
            $points = LineUp::points($order, $round);

            $orders = $run->Orders;
            $orders[] = ['order' => $order, 'points' => $points];
            $over = count($orders) >= count($run->Dealt);

            $run->update([
                'Orders' => $orders,
                'Score' => $run->Score + $points,
                'ScoredAt' => $points > 0 ? now() : $run->ScoredAt,
                'AskedAt' => null,
                'EndedAt' => $over ? now() : null,
            ]);

            return response()->json([
                'round' => count($orders),
                'late' => $late,
                'points' => $points,
                'score' => $run->Score,
                'over' => $over,
                'rounds' => $over ? array_map(fn (array $asked, array $given) => $this->summary($asked, $given), $run->Dealt, $orders) : null,
            ] + self::answer($order, $round));
        });
    }

    /**
     * The next round, after the last one's order and answer, its time starting now.
     */
    public function next(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:64']]);

        return DB::transaction(function () use ($request) {
            $run = $this->run($request);
            abort_unless($run->AskedAt === null, 409, __('This round is still waiting for its line-up.'));

            $run->update(['AskedAt' => now()]);

            return response()->json(['asked' => $this->lineUp->asked($run)]);
        });
    }

    /**
     * What an order shows at its answer: the order as counted, the right one, each suspect's count, and how far off
     * each stood, by where they first stood.
     *
     * @param  list<int>|null  $order
     * @param  array<string, mixed>  $round
     * @return array{order: list<int>|null, truth: list<int>, crimes: list<int>, offs: list<int>|null}
     */
    public static function answer(?array $order, array $round): array
    {
        return [
            'order' => $order,
            'truth' => LineUp::truth($round),
            'crimes' => array_column($round['suspects'], 'crimes'),
            'offs' => LineUp::offs($order, $round),
        ];
    }

    /**
     * A round as the end of the game lists it: its crime, state and year, the suspects in the right order with how
     * far off each stood in the order given (none without one), and its points.
     *
     * @param  array<string, mixed>  $round
     * @param  array{order: list<int>|null, points: int}  $given
     * @return array<string, mixed>
     */
    private function summary(array $round, array $given): array
    {
        $offs = LineUp::offs($given['order'], $round);

        return [
            'type' => __(CrimeData::typeLabel($round['category'], $round['type'])),
            'state' => $round['state'],
            'year' => $round['year'],
            'points' => $given['points'],
            'suspects' => array_map(fn (int $suspect) => [
                'name' => $round['suspects'][$suspect]['name'],
                'crimes' => $round['suspects'][$suspect]['crimes'],
                'off' => $offs[$suspect] ?? null,
            ], LineUp::truth($round)),
        ];
    }

    /**
     * The game the token is for, locked until the request is done, and not over.
     */
    private function run(Request $request): LineUpRun
    {
        $run = LineUpRun::query()->where('Token', $request->input('token'))->lockForUpdate()->first()
            ?? abort(404, __("This game wasn't found. Start a new one."));
        abort_if($run->EndedAt !== null, 409, __('This game has ended. Start a new one.'));

        return $run;
    }
}
