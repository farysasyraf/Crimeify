<?php

namespace Modules\Game\Support;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Game\Entities\GameRun;
use Modules\Game\Entities\LiveAnswer;
use Modules\Game\Entities\LiveGame;
use Modules\Game\Entities\LivePlayer;

// Live games, like a quiz night, of Higher or Lower or DistrictGuessr: the host makes one with a number of rounds and
// shows its code on a big screen, everyone joins with it on their phone, and the host starts it and moves it on round by
// round. Each round shows everyone the same two cards of the same crime, or the same district to pin on the map: first
// config('game.live.ready') seconds to get ready, so every phone shows it at once, then the game's seconds to answer,
// then the answer and the scores, once the time's up or everyone has answered. A right card scores
// config('game.live.points'), a pin its distance's points (DistrictGuessr::points()); either fewer the longer it took,
// by up to the game's share in config('game.live.speed'). A wrong card scores none.
//
// The phones and the big screen check where the game is up to about every second. So they don't each read the
// database, its state is kept in config('game.live.store') for a couple of seconds at a time (state()), and read again
// after every change. The phase (ready, question, reveal) follows from it and the clock, without writing anything.
class LiveGames
{
    /**
     * A round's phases: getting ready, answering, and its answer. Before the first and after the last, the phase is
     * the game's status: LiveGame::Lobby or LiveGame::Finished.
     */
    public const Ready = 'ready';

    public const Question = 'question';

    public const Reveal = 'reveal';

    /**
     * The games a live game can be, by their key on the Games page.
     */
    public const HigherOrLower = 'higher-or-lower';

    public const DistrictGuessr = 'districtguessr';

    public const Skyline = 'skyline';

    /**
     * Their names, as the host chooses one on Game scores.
     */
    public const Kinds = [self::HigherOrLower => 'Higher or Lower', self::DistrictGuessr => 'DistrictGuessr', self::Skyline => 'Skyline'];

    /**
     * Higher or Lower's modes: Classic, everyone plays every round for points; or Last One Standing, where a wrong
     * answer, or none, puts a player out (unless everyone still in gets it wrong, when they all stay in), and the game
     * ends when one is left, or after its rounds. Points still count, to rank those left at the end, and those out in
     * the same round.
     */
    public const Classic = 'classic';

    public const LastOneStanding = 'last-one-standing';

    public const Modes = [self::Classic, self::LastOneStanding];

    public function __construct(private Cards $cards, private DistrictGuessr $guessr, private Skyline $skyline) {}

    /**
     * Make a game of the game given for the host, with every round dealt: for Higher or Lower, its cards, getting
     * closer round by round as the solo game does with its score, in the mode given (Classic or Last One Standing, whose
     * rounds are the most it can last); for DistrictGuessr, its districts, at the level given (DistrictGuessr::Easy or
     * Hard). None when there's nothing to play with.
     */
    public function create(User $host, int $rounds, string $game = self::HigherOrLower, string $difficulty = DistrictGuessr::Hard, string $mode = self::Classic): ?LiveGame
    {
        $dealt = match ($game) {
            self::DistrictGuessr => $this->guessr->deal($rounds),
            self::Skyline => $this->skyline->deal($rounds),
            default => $this->pairs($rounds),
        };

        if ($dealt === null) {
            return null;
        }

        return LiveGame::create([
            'Game' => $game,
            'Mode' => $game === self::HigherOrLower ? $mode : self::Classic,
            'Difficulty' => $game === self::DistrictGuessr ? $difficulty : DistrictGuessr::Hard,
            'Code' => $this->freeCode(),
            'HostId' => $host->Id,
            'HostName' => $host->Name,
            'Rounds' => $rounds,
            'Seconds' => match ($game) {
                self::DistrictGuessr => config('game.districtguessr.seconds'),
                self::Skyline => config('game.skyline.seconds'),
                default => config('game.seconds'),
            },
            'Cards' => $dealt,
            'Status' => LiveGame::Lobby,
            'Round' => 0,
            'CreatedAt' => now(),
        ]);
    }

    /**
     * The game a code is for: the newest with it, made in the last config('game.live.hours'), that hasn't finished.
     */
    public function byCode(string $code): ?LiveGame
    {
        return LiveGame::query()
            ->where('Code', $code)
            ->where('Status', '!=', LiveGame::Finished)
            ->where('CreatedAt', '>=', now()->subHours(config('game.live.hours')))
            ->orderByDesc('Id')
            ->first();
    }

    /**
     * Add a player to the game, at any point before it finishes: one who joins late misses the rounds before, and in
     * Last One Standing, once it has started, watches (OutRound 0), as they couldn't have stayed in through the rounds
     * before. A name someone in the game already has (any capitals) is a UniqueConstraintViolationException.
     */
    public function join(LiveGame $game, string $name): LivePlayer
    {
        $player = LivePlayer::create([
            'LiveGameId' => $game->Id,
            'Token' => Str::random(48),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Score' => 0,
            'OutRound' => $game->Mode === self::LastOneStanding && $game->Round > 0 ? 0 : null,
            'JoinedAt' => now(),
        ]);
        $this->changed($game->Id);

        return $player;
    }

    /**
     * Start the game or move it on, from the lobby or a round's answer: the next round, after a few seconds to get
     * ready, or after the last, the end. In Last One Standing, those out this round go out first, and the game ends
     * when only one is left. False at any other point, as when the host's button is pressed twice.
     */
    public function next(LiveGame $game): bool
    {
        $state = $this->state($game->Id);
        $phase = $state === null ? null : $this->phase($state);

        if (! in_array($phase, [LiveGame::Lobby, self::Reveal], true)) {
            return false;
        }

        if ($game->Round >= $game->Rounds || ($phase === self::Reveal && $this->knockOut($state))) {
            return $this->finish($game);
        }

        $opens = $this->now() + config('game.live.ready') * 1000;

        // Only from the round it was at, so two presses at once move it on once.
        $moved = LiveGame::query()->whereKey($game->Id)->where('Round', $game->Round)->update([
            'Status' => LiveGame::Playing,
            'Round' => $game->Round + 1,
            'OpensAt' => $opens,
            'ClosesAt' => $opens + $game->Seconds * 1000,
        ]);
        $this->changed($game->Id);

        return $moved === 1;
    }

    /**
     * Show the round's answer now, without waiting for the time to run out. False when no round is taking answers.
     */
    public function close(LiveGame $game): bool
    {
        $state = $this->state($game->Id);

        if ($state === null || ! in_array($this->phase($state), [self::Ready, self::Question], true)) {
            return false;
        }

        // Closed long enough ago that the time for answers to arrive has passed too.
        $closed = $this->now() - config('game.live.grace') * 1000;
        $game->update(['OpensAt' => min($game->OpensAt, $closed), 'ClosesAt' => $closed]);
        $this->changed($game->Id);

        return true;
    }

    /**
     * End the game, after its last round or before: its scores are its leaderboard. In Last One Standing, from a
     * round's answer, those out in it go out first; from a round still being answered, no one does.
     */
    public function finish(LiveGame $game): bool
    {
        $state = $this->state($game->Id);

        if ($state !== null && $this->phase($state) === self::Reveal) {
            $this->knockOut($state);
        }

        $game->update(['Status' => LiveGame::Finished, 'FinishedAt' => now()]);
        $this->changed($game->Id);

        return true;
    }

    /**
     * A player's answer to the round given, as the game has it: for Higher or Lower, a guess ('higher' or 'lower'); for
     * DistrictGuessr, a pin (lat and lng); for Skyline, the height (value), up to the round's most. Its points, whether
     * it scored any, and for a pin, its kilometres off. None when that round isn't taking answers: before it shows,
     * after its time, or when it isn't the round being played. Answering a round twice is a
     * UniqueConstraintViolationException.
     *
     * @param  array<string, mixed>  $state
     * @param  array{guess?: string, lat?: float, lng?: float, value?: int}  $input
     * @return array{correct: bool, points: int, km?: float}|null
     */
    public function answer(array $state, int $player, int $round, array $input): ?array
    {
        $now = $this->now();

        // Half a second early is allowed, for a phone whose clock is a little ahead of the server's; and up to when the
        // answer shows (phase()), for one still arriving.
        if ($state['status'] !== LiveGame::Playing || $round !== $state['round']
            || $now < $state['opensAt'] - 500 || $now >= $state['closesAt'] + config('game.live.grace') * 1000) {
            return null;
        }

        $taken = max(0, $now - $state['opensAt']);
        $allowed = $state['seconds'] * 1000;
        $slow = config("game.live.speed.{$state['game']}") * min($taken, $allowed) / $allowed;

        if ($state['game'] === self::DistrictGuessr) {
            $km = round(DistrictGuessr::km($input['lat'], $input['lng'], $state['answer']['lat'], $state['answer']['lng']), 1);
            $points = (int) round(DistrictGuessr::points($km) * (1 - $slow));
            $answer = ['Guess' => 'pin', 'Correct' => $points > 0, 'Lat' => $input['lat'], 'Lng' => $input['lng'], 'Km' => $km];
        } elseif ($state['game'] === self::Skyline) {
            $value = min($input['value'], $state['most']);
            $points = (int) round(Skyline::points($value, $state['answer']) * (1 - $slow));
            $answer = ['Guess' => (string) $value, 'Correct' => $points > 0];
        } else {
            $correct = $input['guess'] === $state['right'];
            $points = $correct ? (int) round(config('game.live.points') * (1 - $slow)) : 0;
            $answer = ['Guess' => $input['guess'], 'Correct' => $correct];
        }

        DB::transaction(function () use ($state, $player, $answer, $points, $taken) {
            LiveAnswer::create($answer + [
                'LivePlayerId' => $player,
                'LiveGameId' => $state['id'],
                'Round' => $state['round'],
                'Points' => $points,
                'Milliseconds' => $taken,
                'AnsweredAt' => now(),
            ]);

            if ($points > 0) {
                LivePlayer::query()->whereKey($player)->increment('Score', $points);
            }
        });
        $this->changed($state['id']);

        return ['correct' => $answer['Correct'], 'points' => $points] + (isset($km) ? ['km' => $km] : []);
    }

    /**
     * Where the game is up to, as the phones and the big screen read it, kept for config('game.live.keep') seconds:
     * which game it is, its round and when it opens and closes, its players and their scores, this round's answers,
     * and its cards in both languages, with the count to guess and whether it's higher, or its district, with where it
     * is: which only the server sees until the answer. Fresh from the database when $fresh. None for a game that isn't
     * there.
     *
     * @return array<string, mixed>|null
     */
    public function state(int $game, bool $fresh = false): ?array
    {
        if ($fresh) {
            $this->store()->forget($this->key($game));
        }

        return $this->store()->remember($this->key($game), config('game.live.keep'), fn () => $this->read($game));
    }

    /**
     * The phase the game is in now: the lobby, getting ready for a round, answering it, its answer, or finished.
     *
     * @param  array<string, mixed>  $state
     */
    public function phase(array $state, ?int $now = null): string
    {
        $now ??= $this->now();

        if ($state['status'] !== LiveGame::Playing) {
            return $state['status'];
        }

        if ($now < $state['opensAt']) {
            return self::Ready;
        }

        // Everyone who can answer: in Last One Standing, those still in.
        $answering = count($this->stillIn($state));
        $everyone = $answering > 0 && count($state['answers']) >= $answering;

        return $everyone || $now >= $state['closesAt'] + config('game.live.grace') * 1000 ? self::Reveal : self::Question;
    }

    /**
     * Whether the game is Higher or Lower's Last One Standing.
     *
     * @param  array<string, mixed>  $state
     */
    public function lastOneStanding(array $state): bool
    {
        return ($state['mode'] ?? self::Classic) === self::LastOneStanding;
    }

    /**
     * The players still in as the round being played began, by Id: everyone, but in Last One Standing those already
     * out or watching.
     *
     * @param  array<string, mixed>  $state
     * @return list<int>
     */
    private function stillIn(array $state): array
    {
        return array_keys(array_filter($state['players'], fn (array $player) => ($player['out'] ?? null) === null));
    }

    /**
     * Each player's round out, as it stands in the phase given: in Last One Standing at a round's answer, those out in
     * it (the state's knocked) count as out in it already, before the host moves on and it's kept. None for still in.
     *
     * @param  array<string, mixed>  $state
     * @return array<int, int|null>
     */
    private function outs(array $state, string $phase): array
    {
        $outs = array_map(fn (array $player) => $player['out'] ?? null, $state['players']);

        if ($this->lastOneStanding($state) && $phase === self::Reveal) {
            foreach ($state['knocked'] as $id) {
                $outs[$id] = $state['round'];
            }
        }

        return $outs;
    }

    /**
     * Whether Last One Standing is decided at this round's answer: of more than one still in as it began, one or none
     * left. A game begun with one player plays all its rounds.
     *
     * @param  array<string, mixed>  $state
     */
    private function decided(array $state): bool
    {
        $before = count($this->stillIn($state));

        return $this->lastOneStanding($state) && $before > 1 && $before - count($state['knocked']) <= 1;
    }

    /**
     * At a round's answer in Last One Standing, keep who went out in it, and say whether the game is decided. For
     * Classic, nothing, and never decided.
     *
     * @param  array<string, mixed>  $state
     */
    private function knockOut(array $state): bool
    {
        if (! $this->lastOneStanding($state)) {
            return false;
        }

        if ($state['knocked'] !== []) {
            LivePlayer::query()->whereKey($state['knocked'])->whereNull('OutRound')->update(['OutRound' => $state['round']]);
        }

        return $this->decided($state);
    }

    /**
     * What a player's phone shows: the phase, the round and its times, the cards in the player's language or the
     * district, and whether they've answered (and where they pinned); at the answer, the count that was hidden or where
     * the district is, and how they did; their score and place, from the answer on (not while answering, which would
     * give away who was right); and at the end, the top 10. In Last One Standing, too, the round the player went out
     * in (none while still in, 0 when watching), and at the answer whether everyone still in got it wrong, so all
     * stayed in.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function forPlayer(array $state, int $player, string $locale): array
    {
        $now = $this->now();
        $phase = $this->phase($state, $now);
        $outs = $this->outs($state, $phase);
        $ranked = $this->ranked($state, $outs);
        $me = collect($ranked)->firstWhere('id', $player);
        $mine = $state['answers'][$player] ?? null;
        $view = $this->common($state, $phase, $now, $outs) + ['name' => $me['name']];
        $pins = $state['game'] === self::DistrictGuessr;

        if ($this->lastOneStanding($state)) {
            $view['out'] = $outs[$player];
            $view['saved'] = $phase === self::Reveal && $state['saved'];
        }

        if (in_array($phase, [self::Ready, self::Question, self::Reveal], true)) {
            $view += match ($state['game']) {
                self::DistrictGuessr => ['district' => $state['district'], 'pin' => $mine === null ? null : ['lat' => $mine['lat'], 'lng' => $mine['lng']]],
                self::Skyline => ['skyline' => $state['skyline'][$locale] ?? $state['skyline']['en']],
                default => ['cards' => $state['cards'][$locale] ?? $state['cards']['en']],
            };
            // Skyline's, the height.
            $view['guess'] = $mine === null ? null : ($state['game'] === self::Skyline ? (int) $mine['guess'] : $mine['guess']);
        }

        if ($phase === self::Reveal) {
            $view['answer'] = $state['answer'];
            $view['result'] = $mine === null ? null : ['correct' => $mine['correct'], 'points' => $mine['points']]
                + ($pins ? ['km' => $mine['km']] : []);
        }

        if ($phase !== self::Question) {
            $view['score'] = $me['score'];
            $view['rank'] = $me['rank'];
        }

        if ($phase === LiveGame::Finished) {
            $view['leaderboard'] = $this->board(array_slice($ranked, 0, 10), $player);
            // DistrictGuessr's awards, the player's own marked.
            $view['awards'] = array_map(
                fn (array $award) => array_diff_key($award, ['player' => 0]) + ['you' => $award['player'] === $player],
                $state['awards'] ?? [],
            );
        }

        return $view;
    }

    /**
     * What the big screen shows: the phase, the round and its times, the players by name while they join, how many
     * have answered, the cards or the district; at the answer, the count that was hidden and how many said higher and
     * lower, or where the district is and everyone's pins, closest first; the top 5; and at the end, everyone. In Last
     * One Standing at the answer, too, who went out, or whether everyone still in got it wrong, so all stayed in.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function forHost(array $state): array
    {
        $now = $this->now();
        $phase = $this->phase($state, $now);
        $outs = $this->outs($state, $phase);
        $ranked = $this->ranked($state, $outs);
        $view = $this->common($state, $phase, $now, $outs) + ['answered' => count($state['answers'])];
        $pins = $state['game'] === self::DistrictGuessr;

        if ($this->lastOneStanding($state) && $phase === self::Reveal) {
            $view['knocked'] = array_map(fn (int $id) => $state['players'][$id]['name'], $state['knocked']);
            $view['saved'] = $state['saved'];
        }

        if ($phase === LiveGame::Lobby) {
            $view['names'] = array_column($state['players'], 'name');
        }

        if (in_array($phase, [self::Ready, self::Question, self::Reveal], true)) {
            $view += match ($state['game']) {
                self::DistrictGuessr => ['district' => $state['district']],
                self::Skyline => ['skyline' => $state['skyline']['en']],
                default => ['cards' => $state['cards']['en']],
            };
        }

        if ($phase === self::Reveal) {
            $view['answer'] = $state['answer'];

            if ($pins) {
                $view['pins'] = collect($state['answers'])
                    ->map(fn (array $answer, int $id) => [
                        'name' => $state['players'][$id]['name'] ?? '',
                        'lat' => $answer['lat'],
                        'lng' => $answer['lng'],
                        'km' => $answer['km'],
                        'points' => $answer['points'],
                    ])
                    ->sortBy([['km', 'asc'], fn (array $a, array $b) => strcasecmp($a['name'], $b['name'])])
                    ->values()
                    ->all();
            } elseif ($state['game'] === self::Skyline) {
                // Everyone's height, the closest first.
                $view['guesses'] = collect($state['answers'])
                    ->map(fn (array $answer, int $id) => [
                        'name' => $state['players'][$id]['name'] ?? '',
                        'value' => (int) $answer['guess'],
                        'off' => abs((int) $answer['guess'] - $state['answer']),
                        'points' => $answer['points'],
                    ])
                    ->sortBy([['off', 'asc'], fn (array $a, array $b) => strcasecmp($a['name'], $b['name'])])
                    ->values()
                    ->all();
            } else {
                $guesses = array_count_values(array_column($state['answers'], 'guess'));
                $view['right'] = $state['right'];
                $view['said'] = ['higher' => $guesses['higher'] ?? 0, 'lower' => $guesses['lower'] ?? 0];
            }

            $view['leaderboard'] = $this->board(array_slice($ranked, 0, 5));
            $view['last'] = $state['round'] >= $state['rounds'] || $this->decided($state);
        }

        if ($phase === LiveGame::Finished) {
            $view['leaderboard'] = $this->board($ranked);
            $view['awards'] = array_map(fn (array $award) => array_diff_key($award, ['player' => 0]), $state['awards'] ?? []);
        }

        return $view;
    }

    /**
     * A finished DistrictGuessr game's awards, so more than the top 3 have something to cheer, each with its player's
     * Id and name and what earned it:
     *
     * - Sharpshooter: the most pins within config('game.live.awards.near') km (of the same, the closer on average);
     *   when no one had one, the closest pin of the game.
     * - Speed Demon: the fastest confirm on average, of those who pinned at least half the rounds.
     * - Comeback: the most places climbed over the last config('game.live.awards.last') rounds, at least one.
     * - Explorer: the furthest from the districts, all their pins together.
     *
     * Each goes to someone without an award yet if there's anyone who qualifies, the next best; otherwise to the best.
     * Of the same, by name. None for Higher or Lower, or a game ended before its first round.
     *
     * @return list<array<string, mixed>>
     */
    public function awards(LiveGame $game): array
    {
        $played = $game->Round;

        if ($game->Game !== self::DistrictGuessr || $played === 0) {
            return [];
        }

        $config = config('game.live.awards');
        $names = LivePlayer::query()->where('LiveGameId', $game->Id)->pluck('PlayerName', 'Id')->all();
        $answers = LiveAnswer::query()->where('LiveGameId', $game->Id)->get(['LivePlayerId', 'Round', 'Points', 'Milliseconds', 'Km']);
        $byName = fn (int $a, int $b) => strcasecmp($names[$a], $names[$b]);

        // Each player's pins: how many, how many near, all their kilometres, their closest, and their average time.
        $pins = $answers->whereNotNull('Km')->groupBy('LivePlayerId')->map(fn ($mine) => [
            'count' => $mine->count(),
            'near' => $mine->where('Km', '<=', $config['near'])->count(),
            'km' => round($mine->sum('Km'), 1),
            'closest' => (float) $mine->min('Km'),
            'ms' => $mine->avg('Milliseconds'),
        ]);

        // The players by score after a round, and their places, as the leaderboard has them.
        $places = function (int $round) use ($answers, $names): array {
            $scores = [];
            foreach (array_keys($names) as $id) {
                $scores[$id] = (int) $answers->where('LivePlayerId', $id)->where('Round', '<=', $round)->sum('Points');
            }
            uksort($scores, fn (int $a, int $b) => $scores[$b] <=> $scores[$a] ?: strcasecmp($names[$a], $names[$b]));

            $place = [];
            $previous = null;
            foreach (array_keys($scores) as $index => $id) {
                $place[$id] = $previous !== null && $scores[$id] === $scores[$previous] ? $place[$previous] : $index + 1;
                $previous = $id;
            }

            return $place;
        };
        $from = max(1, $played - $config['last']);
        [$before, $after] = [$places($from), $places($played)];

        $near = $pins->filter(fn (array $p) => $p['near'] > 0)->keys()
            ->sort(fn (int $a, int $b) => $pins[$b]['near'] <=> $pins[$a]['near'] ?: $pins[$a]['km'] / $pins[$a]['count'] <=> $pins[$b]['km'] / $pins[$b]['count'] ?: $byName($a, $b));
        $candidates = [
            'sharpshooter' => $near->isNotEmpty()
                ? $near->map(fn (int $id) => ['player' => $id, 'count' => $pins[$id]['near'], 'km' => $config['near']])
                : $pins->keys()->sort(fn (int $a, int $b) => $pins[$a]['closest'] <=> $pins[$b]['closest'] ?: $byName($a, $b))
                    ->map(fn (int $id) => ['player' => $id, 'closest' => $pins[$id]['closest']]),
            'speed' => $pins->filter(fn (array $p) => $p['count'] >= (int) ceil($played / 2))->keys()
                ->sort(fn (int $a, int $b) => $pins[$a]['ms'] <=> $pins[$b]['ms'] ?: $byName($a, $b))
                ->map(fn (int $id) => ['player' => $id, 'seconds' => round($pins[$id]['ms'] / 1000, 1)]),
            'comeback' => collect(array_keys($names))->filter(fn (int $id) => $before[$id] - $after[$id] >= 1)
                ->sort(fn (int $a, int $b) => ($before[$b] - $after[$b]) <=> ($before[$a] - $after[$a]) ?: $after[$a] <=> $after[$b] ?: $byName($a, $b))
                ->map(fn (int $id) => ['player' => $id, 'places' => $before[$id] - $after[$id], 'rounds' => $played - $from]),
            'explorer' => $pins->filter(fn (array $p) => $p['km'] > 0)->keys()
                ->sort(fn (int $a, int $b) => $pins[$b]['km'] <=> $pins[$a]['km'] ?: $byName($a, $b))
                ->map(fn (int $id) => ['player' => $id, 'km' => $pins[$id]['km']]),
        ];

        $awards = [];
        $awarded = [];
        foreach ($candidates as $award => $ranked) {
            $winner = $ranked->first(fn (array $candidate) => ! isset($awarded[$candidate['player']])) ?? $ranked->first();

            if ($winner !== null) {
                $awards[] = ['award' => $award, 'player' => $winner['player'], 'name' => $names[$winner['player']]] + array_diff_key($winner, ['player' => 0]);
                $awarded[$winner['player']] = true;
            }
        }

        return $awards;
    }

    /**
     * The players, most points first, each with their place: the same points, the same place (1, 2, 2, 4), listed by
     * name. In Last One Standing, those still in first, then the others by the round they went out in, the latest
     * first, those watching last, each by points; with the round they went out in (outs(), as of the phase).
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, int|null>|null  $outs
     * @return list<array{id: int, name: string, score: int, rank: int, out?: int|null}>
     */
    public function ranked(array $state, ?array $outs = null): array
    {
        $standing = $this->lastOneStanding($state);
        $outs ??= $this->outs($state, LiveGame::Finished);
        // What places a player: the round they went out in, still in counting above any, then their points.
        $key = fn (array $player) => $standing ? [$player['out'] ?? PHP_INT_MAX, $player['score']] : [$player['score']];

        $players = collect($state['players'])
            ->map(fn (array $player, int $id) => ['id' => $id, 'name' => $player['name'], 'score' => $player['score']]
                + ($standing ? ['out' => $outs[$id] ?? null] : []))
            ->sort(fn (array $a, array $b) => $key($b) <=> $key($a) ?: strcasecmp($a['name'], $b['name']))
            ->values();

        $ranked = [];
        foreach ($players as $index => $player) {
            $ranked[] = $player + ['rank' => $index > 0 && $key($player) === $key($ranked[$index - 1]) ? $ranked[$index - 1]['rank'] : $index + 1];
        }

        return $ranked;
    }

    /**
     * The milliseconds since 1970 now, as the server's clock (and a test's) has it.
     */
    public function now(): int
    {
        return (int) Carbon::now()->getTimestampMs();
    }

    /**
     * What both views have: in Last One Standing, too, how many are still in (left), as of the phase.
     *
     * @param  array<string, mixed>  $state
     * @param  array<int, int|null>  $outs
     * @return array<string, mixed>
     */
    private function common(array $state, string $phase, int $now, array $outs): array
    {
        return [
            'game' => $state['game'],
            'mode' => $state['mode'] ?? self::Classic,
            'difficulty' => $state['difficulty'] ?? DistrictGuessr::Hard,
            'now' => $now,
            'phase' => $phase,
            'round' => $state['round'],
            'rounds' => $state['rounds'],
            'seconds' => $state['seconds'],
            'opensAt' => $state['opensAt'],
            'closesAt' => $state['closesAt'],
            'players' => count($state['players']),
        ] + ($this->lastOneStanding($state) ? ['left' => count(array_filter($outs, fn (?int $out) => $out === null))] : []);
    }

    /**
     * Ranked players as a leaderboard shows them, the given player marked as "you", and in Last One Standing the round
     * each went out in (none still in, 0 watching).
     *
     * @param  list<array{id: int, name: string, score: int, rank: int, out?: int|null}>  $ranked
     * @return list<array{rank: int, name: string, score: int, you: bool, out?: int|null}>
     */
    private function board(array $ranked, ?int $you = null): array
    {
        return array_map(fn (array $player) => [
            'rank' => $player['rank'],
            'name' => $player['name'],
            'score' => $player['score'],
            'you' => $player['id'] === $you,
        ] + (array_key_exists('out', $player) ? ['out' => $player['out']] : []), $ranked);
    }

    /**
     * The game's state from the database.
     *
     * @return array<string, mixed>|null
     */
    private function read(int $id): ?array
    {
        $game = LiveGame::find($id);

        if ($game === null) {
            return null;
        }

        $players = LivePlayer::query()->where('LiveGameId', $id)->orderBy('Id')->get();
        $answers = $game->Round === 0 ? collect() : LiveAnswer::query()->where('LiveGameId', $id)->where('Round', $game->Round)->get();
        $dealt = $game->Round === 0 ? null : $game->Cards[$game->Round - 1];

        // Last One Standing: who goes out at this round's answer, those still in without a right one, unless that's
        // every one of them, when everyone stays in (saved). Only final once the round's answer shows.
        $in = $players->whereNull('OutRound')->pluck('Id');
        $knocked = $in->diff($answers->where('Correct', true)->pluck('LivePlayerId'))->values()->all();
        $saved = $in->isNotEmpty() && count($knocked) === $in->count();

        return [
            'id' => $game->Id,
            'game' => $game->Game,
            'mode' => $game->Mode ?? self::Classic,
            'knocked' => $saved ? [] : $knocked,
            'saved' => $saved,
            'difficulty' => $game->Difficulty ?? DistrictGuessr::Hard,
            'code' => $game->Code,
            'status' => $game->Status,
            'round' => $game->Round,
            'rounds' => $game->Rounds,
            'seconds' => $game->Seconds,
            'opensAt' => $game->OpensAt,
            'closesAt' => $game->ClosesAt,
            'players' => $players->mapWithKeys(fn (LivePlayer $player) => [$player->Id => [
                'name' => $player->PlayerName,
                'token' => $player->Token,
                'score' => $player->Score,
                'out' => $player->OutRound,
            ]])->all(),
            'answers' => $answers->mapWithKeys(fn (LiveAnswer $answer) => [$answer->LivePlayerId => [
                'guess' => $answer->Guess,
                'correct' => $answer->Correct,
                'points' => $answer->Points,
                'lat' => $answer->Lat,
                'lng' => $answer->Lng,
                'km' => $answer->Km,
            ]])->all(),
            // Once it's over.
            'awards' => $game->Status === LiveGame::Finished ? $this->awards($game) : [],
        ] + match ($game->Game) {
            self::DistrictGuessr => $this->district($dealt),
            self::Skyline => $this->skylineRound($dealt),
            default => $this->pair($dealt),
        };
    }

    /**
     * A Higher or Lower round's part of the state: its cards in both languages, the count hidden on the second, and
     * whether it's higher.
     *
     * @param  array{0: array<string, mixed>, 1: array<string, mixed>}|null  $pair
     * @return array{cards: array<string, mixed>|null, answer: int|null, right: string|null}
     */
    private function pair(?array $pair): array
    {
        [$shown, $next] = $pair ?? [null, null];

        return [
            'cards' => $shown === null ? null : collect(['en', 'ms'])->mapWithKeys(fn (string $locale) => [$locale => [
                $this->cards->face($shown, withCount: true, locale: $locale),
                $this->cards->face($next, withCount: false, locale: $locale),
            ]])->all(),
            'answer' => $next['crimes'] ?? null,
            'right' => $next === null ? null : ($next['crimes'] > $shown['crimes'] ? 'higher' : 'lower'),
        ];
    }

    /**
     * A DistrictGuessr round's part of the state: the district's name and the region its pin is in, with the region's
     * code for Easy's zoom, and where it is.
     *
     * @param  array{name: string, place: string, region?: string, lat: float, lng: float}|null  $district
     * @return array{district: array{name: string, place: string, region: string|null}|null, answer: array{lat: float, lng: float}|null}
     */
    private function district(?array $district): array
    {
        return [
            'district' => $district === null ? null : ['name' => $district['name'], 'place' => $district['place'], 'region' => $district['region'] ?? null],
            'answer' => $district === null ? null : ['lat' => $district['lat'], 'lng' => $district['lng']],
        ];
    }

    /**
     * A Skyline round's part of the state: what the screens show of it in both languages (Skyline::face()), the count
     * of the tower to guess, and how high a guess can go.
     *
     * @param  array<string, mixed>|null  $round
     * @return array{skyline: array<string, array<string, mixed>>|null, answer: int|null, most: int|null}
     */
    private function skylineRound(?array $round): array
    {
        return [
            'skyline' => $round === null ? null : ['en' => Skyline::face($round, 'en'), 'ms' => Skyline::face($round, 'ms')],
            'answer' => $round['crimes'] ?? null,
            'most' => $round === null ? null : Skyline::most($round),
        ];
    }

    /**
     * Higher or Lower's cards for the rounds given, getting closer round by round as the solo game does with its score.
     * None when there are no cards to play with.
     *
     * @return list<array<int, array<string, mixed>>>|null
     */
    private function pairs(int $rounds): ?array
    {
        $cards = [];

        for ($round = 0; $round < $rounds; $round++) {
            $pair = $this->cards->deal($round);

            if ($pair === null) {
                return null;
            }

            $cards[] = $pair;
        }

        return $cards;
    }

    /**
     * After a change, read the game again now, so the next check has it.
     */
    private function changed(int $game): void
    {
        $this->state($game, fresh: true);
    }

    /**
     * Six digits, from 100000, that no game which can still be joined has.
     */
    private function freeCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while ($this->byCode($code) !== null);

        return $code;
    }

    private function store(): Repository
    {
        return Cache::store(config('game.live.store'));
    }

    private function key(int $game): string
    {
        return "game.live.{$game}";
    }
}
