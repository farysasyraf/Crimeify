<?php

namespace Modules\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetPublicLocale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Game\Entities\GameRun;
use Modules\Game\Support\CaseFile;
use Modules\Game\Support\LineUp;
use Modules\Game\Support\LiveGames;

// Playing a live game of Higher or Lower, DistrictGuessr, Skyline, Case File or Line-Up on a phone (LiveGames), for everyone without logging in: the page to join with
// the code on the big screen (/public/game/live), then, from live.js, joining, checking the game about every second, and
// answering. Those three are in Routes/api.php, without a session, so the checks don't each write one to the database;
// the phone says who it is with the player's Id and token in the X-Player and X-Player-Token headers, and its language
// with ?lang=.
class LivePlayController extends Controller
{
    public function __construct(private LiveGames $live) {}

    /**
     * The page to join and play on, in the public pages' layout, with the code filled in from ?code= if it's given. It
     * has what either game needs, as which it is only shows once the player has joined.
     */
    public function page(Request $request): View
    {
        $code = $request->query('code');
        $language = app()->getLocale() === SetPublicLocale::defaultLocale() ? '' : '?lang='.app()->getLocale();

        return view('game::live', [
            'code' => is_string($code) && preg_match('/^\d{6}$/', $code) ? $code : '',
            'nameLength' => config('game.name_length'),
            'api' => url('/api/game/live'),
            'language' => $language,
            'boundaries' => versioned_asset(config('map.boundaries')),
            // For how to play DistrictGuessr: the share of a pin's points kept at the last moment, as a percentage.
            'guessr' => config('game.districtguessr') + ['keeps' => (int) round(100 * (1 - config('game.live.speed.districtguessr')))],
            // And Skyline's.
            'skyline' => config('game.skyline') + ['keeps' => (int) round(100 * (1 - config('game.live.speed.skyline')))],
            // And Case File's: a clue every few seconds, and what a right pick is worth with the first and every clue.
            'caseFile' => ['every' => config('game.case_file.every'), 'clues' => count(CaseFile::order()), 'most' => CaseFile::worthAt(1), 'least' => CaseFile::worthAt(count(CaseFile::order()))],
            // And Line-Up's: its points, and the share of them kept at the last moment.
            'lineUp' => config('game.line_up') + ['most' => LineUp::most(), 'keeps' => (int) round(100 * (1 - config('game.live.speed.line-up')))],
        ]);
    }

    /**
     * Join the game with the code given, under the name given: its Id, and the player's Id and token for the checks
     * and answers to come.
     */
    public function join(Request $request): JsonResponse
    {
        $this->speak($request);
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [
            'code.required' => __('Type the code on the big screen.'),
            'code.regex' => __('The code is the six numbers on the big screen.'),
        ]);
        $name = GameRun::validName($request);

        $game = $this->live->byCode($request->input('code')) ?? abort(404, __('No game has that code. Check it on the big screen.'));

        try {
            $player = $this->live->join($game, $name);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('Someone in this game already has that name. Add an initial, or choose another.')]);
        }

        return response()->json(['game' => $game->Id, 'player' => $player->Id, 'token' => $player->Token, 'name' => $player->PlayerName]);
    }

    /**
     * Where the game is up to, as the player's phone shows it.
     */
    public function state(Request $request, int $game): JsonResponse
    {
        $this->speak($request);
        [$state, $player] = $this->player($request, $game);

        return response()->json($this->live->forPlayer($state, $player, app()->getLocale()));
    }

    /**
     * The player's answer to the round given: higher or lower, a pin on the map (lat and lng) in DistrictGuessr, a
     * tower's height (value) in Skyline, a suspect by their place on the board in Case File, or in Line-Up, the order of
     * the suspects (order), each by where they first stood. How it did shows with the round's answer.
     */
    public function answer(Request $request, int $game): JsonResponse
    {
        $this->speak($request);
        [$state, $player] = $this->player($request, $game);
        // In Last One Standing, only those still in answer.
        abort_if($this->live->lastOneStanding($state) && ($state['players'][$player]['out'] ?? null) !== null, 409, __("You're out of this game: watch how it ends."));
        $request->validate(['round' => ['required', 'integer']] + match ($state['game']) {
            LiveGames::DistrictGuessr => ['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']],
            LiveGames::Skyline => ['value' => ['required', 'integer', 'min:0', 'max:1000000']],
            LiveGames::CaseFile => ['suspect' => ['required', 'integer', 'min:0', 'max:'.(count($state['suspects'] ?? []) - 1)]],
            LiveGames::LineUp => [
                'order' => ['required', 'array', 'size:'.config('game.line_up.suspects')],
                'order.*' => ['integer', 'distinct', 'min:0', 'max:'.(config('game.line_up.suspects') - 1)],
            ],
            default => ['guess' => ['required', 'in:higher,lower']],
        });
        $input = match ($state['game']) {
            LiveGames::DistrictGuessr => ['lat' => (float) $request->input('lat'), 'lng' => (float) $request->input('lng')],
            LiveGames::Skyline => ['value' => $request->integer('value')],
            LiveGames::CaseFile => ['suspect' => $request->integer('suspect')],
            LiveGames::LineUp => ['order' => array_map('intval', $request->input('order'))],
            default => ['guess' => (string) $request->input('guess')],
        };

        try {
            // A round just started may be newer than what's kept: read the game again before saying it's not taking answers.
            $result = $this->live->answer($state, $player, $request->integer('round'), $input)
                ?? $this->live->answer($this->live->state($game, fresh: true) ?? $state, $player, $request->integer('round'), $input);
        } catch (UniqueConstraintViolationException) {
            abort(409, __("You've answered this round already."));
        }

        abort_if($result === null, 409, __("This round isn't taking answers now."));

        return response()->json(['received' => true]);
    }

    /**
     * The game's state and the player asking, from their Id and token. Someone who just joined may be newer than
     * what's kept, so the game is read again before saying they aren't in it.
     *
     * @return array{array<string, mixed>, int}
     */
    private function player(Request $request, int $game): array
    {
        $id = (int) $request->header('X-Player');
        $token = (string) $request->header('X-Player-Token');
        $isIn = fn (?array $state) => $state !== null && isset($state['players'][$id]) && hash_equals($state['players'][$id]['token'], $token);

        $state = $this->live->state($game);

        if (! $isIn($state)) {
            $state = $this->live->state($game, fresh: true);
        }

        abort_if($state === null, 404, __('This game is no longer there.'));
        abort_unless($isIn($state), 403, __("You're not in this game. Join it with its code."));

        return [$state, $id];
    }

    /**
     * The language asked for with ?lang=, as the public pages have it, without the cookie they keep it in: these
     * routes have no session or cookies, so nothing is changed for the pages.
     */
    private function speak(Request $request): void
    {
        $lang = $request->query('lang');
        App::setLocale(is_string($lang) && in_array($lang, SetPublicLocale::Locales, true) ? $lang : SetPublicLocale::defaultLocale());
    }
}
