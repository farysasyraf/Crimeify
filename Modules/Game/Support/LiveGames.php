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

// Live games of Higher or Lower, like a quiz night: the host makes one with a number of rounds and shows its code on a
// big screen, everyone joins with it on their phone, and the host starts it and moves it on round by round. Each round
// shows everyone the same two cards of the same crime: first config('game.live.ready') seconds to get ready, so every
// phone shows them at once, then config('game.seconds') to answer, then the answer and the scores, once the time's up
// or everyone has answered. A right answer scores config('game.live.points') straight away, fewer the longer it takes,
// down to half at the last moment; a wrong one, none.
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

    public function __construct(private Cards $cards) {}

    /**
     * Make a game for the host, with every round's cards dealt, getting closer round by round as the solo game does
     * with its score. None when there are no cards to play with.
     */
    public function create(User $host, int $rounds): ?LiveGame
    {
        $cards = [];

        for ($round = 0; $round < $rounds; $round++) {
            $pair = $this->cards->deal($round);

            if ($pair === null) {
                return null;
            }

            $cards[] = $pair;
        }

        return LiveGame::create([
            'Code' => $this->freeCode(),
            'HostId' => $host->Id,
            'HostName' => $host->Name,
            'Rounds' => $rounds,
            'Seconds' => config('game.seconds'),
            'Cards' => $cards,
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
     * Add a player to the game, at any point before it finishes: one who joins late misses the rounds before.
     * A name someone in the game already has (any capitals) is a UniqueConstraintViolationException.
     */
    public function join(LiveGame $game, string $name): LivePlayer
    {
        $player = LivePlayer::create([
            'LiveGameId' => $game->Id,
            'Token' => Str::random(48),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Score' => 0,
            'JoinedAt' => now(),
        ]);
        $this->changed($game->Id);

        return $player;
    }

    /**
     * Start the game or move it on, from the lobby or a round's answer: the next round, after a few seconds to get
     * ready, or after the last, the end. False at any other point, as when the host's button is pressed twice.
     */
    public function next(LiveGame $game): bool
    {
        $state = $this->state($game->Id);

        if ($state === null || ! in_array($this->phase($state), [LiveGame::Lobby, self::Reveal], true)) {
            return false;
        }

        if ($game->Round >= $game->Rounds) {
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
     * End the game, after its last round or before: its scores are its leaderboard.
     */
    public function finish(LiveGame $game): bool
    {
        $game->update(['Status' => LiveGame::Finished, 'FinishedAt' => now()]);
        $this->changed($game->Id);

        return true;
    }

    /**
     * A player's answer to the round given: whether it was right and its points. None when that round isn't taking
     * answers: before its cards show, after its time, or when it isn't the round being played. Answering a round
     * twice is a UniqueConstraintViolationException.
     *
     * @param  array<string, mixed>  $state
     * @return array{correct: bool, points: int}|null
     */
    public function answer(array $state, int $player, int $round, string $guess): ?array
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
        $correct = $guess === $state['right'];
        $points = $correct ? (int) round(config('game.live.points') * (1 - min($taken, $allowed) / $allowed / 2)) : 0;

        DB::transaction(function () use ($state, $player, $guess, $correct, $points, $taken) {
            LiveAnswer::create([
                'LivePlayerId' => $player,
                'LiveGameId' => $state['id'],
                'Round' => $state['round'],
                'Guess' => $guess,
                'Correct' => $correct,
                'Points' => $points,
                'Milliseconds' => $taken,
                'AnsweredAt' => now(),
            ]);

            if ($points > 0) {
                LivePlayer::query()->whereKey($player)->increment('Score', $points);
            }
        });
        $this->changed($state['id']);

        return ['correct' => $correct, 'points' => $points];
    }

    /**
     * Where the game is up to, as the phones and the big screen read it, kept for config('game.live.keep') seconds:
     * its round and when it opens and closes, its players and their scores, this round's answers, and its cards in
     * both languages, with the count to guess and whether it's higher, which only the server sees until the answer.
     * Fresh from the database when $fresh. None for a game that isn't there.
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

        $everyone = $state['players'] !== [] && count($state['answers']) >= count($state['players']);

        return $everyone || $now >= $state['closesAt'] + config('game.live.grace') * 1000 ? self::Reveal : self::Question;
    }

    /**
     * What a player's phone shows: the phase, the round and its times, the cards in the player's language, whether
     * they've answered; at the answer, the count that was hidden and how they did; their score and place, from the
     * answer on (not while answering, which would give away who was right); and at the end, the top 10.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function forPlayer(array $state, int $player, string $locale): array
    {
        $now = $this->now();
        $phase = $this->phase($state, $now);
        $ranked = $this->ranked($state);
        $me = collect($ranked)->firstWhere('id', $player);
        $mine = $state['answers'][$player] ?? null;
        $view = $this->common($state, $phase, $now) + ['name' => $me['name']];

        if (in_array($phase, [self::Ready, self::Question, self::Reveal], true)) {
            $view['cards'] = $state['cards'][$locale] ?? $state['cards']['en'];
            $view['guess'] = $mine['guess'] ?? null;
        }

        if ($phase === self::Reveal) {
            $view['answer'] = $state['answer'];
            $view['result'] = $mine === null ? null : ['correct' => $mine['correct'], 'points' => $mine['points']];
        }

        if ($phase !== self::Question) {
            $view['score'] = $me['score'];
            $view['rank'] = $me['rank'];
        }

        if ($phase === LiveGame::Finished) {
            $view['leaderboard'] = $this->board(array_slice($ranked, 0, 10), $player);
        }

        return $view;
    }

    /**
     * What the big screen shows: the phase, the round and its times, the players by name while they join, how many
     * have answered, the cards; at the answer, the count that was hidden, how many said higher and lower, and the top
     * 5; and at the end, everyone.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function forHost(array $state): array
    {
        $now = $this->now();
        $phase = $this->phase($state, $now);
        $ranked = $this->ranked($state);
        $view = $this->common($state, $phase, $now) + ['answered' => count($state['answers'])];

        if ($phase === LiveGame::Lobby) {
            $view['names'] = array_column($state['players'], 'name');
        }

        if (in_array($phase, [self::Ready, self::Question, self::Reveal], true)) {
            $view['cards'] = $state['cards']['en'];
        }

        if ($phase === self::Reveal) {
            $guesses = array_count_values(array_column($state['answers'], 'guess'));
            $view['answer'] = $state['answer'];
            $view['right'] = $state['right'];
            $view['said'] = ['higher' => $guesses['higher'] ?? 0, 'lower' => $guesses['lower'] ?? 0];
            $view['leaderboard'] = $this->board(array_slice($ranked, 0, 5));
            $view['last'] = $state['round'] >= $state['rounds'];
        }

        if ($phase === LiveGame::Finished) {
            $view['leaderboard'] = $this->board($ranked);
        }

        return $view;
    }

    /**
     * The players, most points first, each with their place: the same points, the same place (1, 2, 2, 4), listed by
     * name.
     *
     * @param  array<string, mixed>  $state
     * @return list<array{id: int, name: string, score: int, rank: int}>
     */
    public function ranked(array $state): array
    {
        $players = collect($state['players'])
            ->map(fn (array $player, int $id) => ['id' => $id, 'name' => $player['name'], 'score' => $player['score']])
            ->sortBy([['score', 'desc'], fn (array $a, array $b) => strcasecmp($a['name'], $b['name'])])
            ->values();

        $ranked = [];
        foreach ($players as $index => $player) {
            $ranked[] = $player + ['rank' => $index > 0 && $player['score'] === $ranked[$index - 1]['score'] ? $ranked[$index - 1]['rank'] : $index + 1];
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
     * What both views have.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function common(array $state, string $phase, int $now): array
    {
        return [
            'now' => $now,
            'phase' => $phase,
            'round' => $state['round'],
            'rounds' => $state['rounds'],
            'seconds' => $state['seconds'],
            'opensAt' => $state['opensAt'],
            'closesAt' => $state['closesAt'],
            'players' => count($state['players']),
        ];
    }

    /**
     * Ranked players as a leaderboard shows them, the given player marked as "you".
     *
     * @param  list<array{id: int, name: string, score: int, rank: int}>  $ranked
     * @return list<array{rank: int, name: string, score: int, you: bool}>
     */
    private function board(array $ranked, ?int $you = null): array
    {
        return array_map(fn (array $player) => [
            'rank' => $player['rank'],
            'name' => $player['name'],
            'score' => $player['score'],
            'you' => $player['id'] === $you,
        ], $ranked);
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
        [$shown, $next] = $game->Round === 0 ? [null, null] : $game->Cards[$game->Round - 1];

        return [
            'id' => $game->Id,
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
            ]])->all(),
            'answers' => $answers->mapWithKeys(fn (LiveAnswer $answer) => [$answer->LivePlayerId => [
                'guess' => $answer->Guess,
                'correct' => $answer->Correct,
                'points' => $answer->Points,
            ]])->all(),
            'cards' => $shown === null ? null : collect(['en', 'ms'])->mapWithKeys(fn (string $locale) => [$locale => [
                $this->cards->face($shown, withCount: true, locale: $locale),
                $this->cards->face($next, withCount: false, locale: $locale),
            ]])->all(),
            'answer' => $next['crimes'] ?? null,
            'right' => $next === null ? null : ($next['crimes'] > $shown['crimes'] ? 'higher' : 'lower'),
        ];
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
