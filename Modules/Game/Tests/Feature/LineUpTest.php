<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Game\Entities\GameWinner;
use Modules\Game\Entities\LineUpRun;
use Modules\Game\Entities\LiveGame;
use Modules\Game\Support\LineUp;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class LineUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test's own live games, not a file another test left.
        config(['game.live.store' => 'array']);
    }

    /**
     * Police districts in three states, each with break-ins and other thefts in 2022 and 2023: twelve rounds to deal.
     * District n's count is 10n, a little more for other thefts and for 2023, so each is far enough from the next.
     *
     * @param  array<string, string>  $states
     */
    private function figures(array $states = ['Johor' => 'MY-01', 'Perak' => 'MY-08', 'Selangor' => 'MY-10'], int $districts = 6): void
    {
        foreach ($states as $state => $region) {
            foreach (range(1, $districts) as $n) {
                PoliceDistrict::create(['State' => $state, 'Name' => "{$state} {$n}", 'Region' => $region, 'Latitude' => 2 + $n / 10, 'Longitude' => 101 + $n / 10]);

                foreach (['break_in' => 0, 'theft_other' => 1] as $type => $more) {
                    foreach ([2022, 2023] as $year) {
                        CrimeStat::create([
                            'State' => $state, 'District' => "{$state} {$n}", 'Category' => 'property', 'Type' => $type,
                            'Year' => $year, 'Crimes' => 10 * $n + $more + ($year - 2022) * 2,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Start a game as Siti: its token.
     */
    private function start(): string
    {
        return $this->postJson('/public/game/line-up/start', ['name' => 'Siti'])->assertOk()->json('token');
    }

    /**
     * The round being ordered, from the game kept on the server.
     *
     * @return array<string, mixed>
     */
    private function asked(): array
    {
        $run = LineUpRun::query()->latest('Id')->firstOrFail();

        return $run->Dealt[$run->round() - 1];
    }

    /**
     * @param  list<int>|null  $order
     */
    private function order(string $token, ?array $order): TestResponse
    {
        return $this->postJson('/public/game/line-up/order', ['token' => $token, 'order' => $order]);
    }

    public function test_the_game_has_its_page_and_its_tile_on_the_games_page(): void
    {
        $this->figures();

        $this->get('/public/game/line-up')->assertOk()
            ->assertSee('<title>Line-Up · '.config('app.name').'</title>', false)
            ->assertSee('data-start="'.route('public.game.line-up.start').'"', false)
            ->assertSee('data-boundaries="'.versioned_asset('modules/map/malaysia-states.geojson').'"', false)
            ->assertSee('data-seconds="30" data-rounds="10" data-most="1000"', false)
            // The suspects' drawings, once, for the room to use, and the room.
            ->assertSee('<symbol id="lu-cap"', false)
            ->assertSee('<symbol id="lu-hand"', false)
            ->assertSeeInOrder(['Witness instructions', '5 police districts of one state stand in the line-up, each holding its name.',
                '200 points for each in its place, 100 if it&#039;s one place off: up to 1,000 a round.', 'Witness', 'Bring them in'], false)
            // The room, its controls along its foot.
            ->assertSeeInOrder(['data-lu-room data-mode="solo"', 'data-lu="map"', 'data-lu="line"', 'data-lu="floor"', 'data-lu="console"',
                'data-lu="scale"', 'Most crime', 'data-lu="result"', 'data-lu="help"', 'data-lu="confirm"', 'Confirm the line-up', 'data-lu="next"'], false)
            ->assertSeeInOrder([versioned_asset('modules/game/line-up-room.js'), versioned_asset('modules/game/line-up.js')], false)
            ->assertSee('<a href="'.route('public.game').'" class="public-link active" aria-current="page">', false);

        // On the Games page, after Case File.
        $this->get('/public/game')->assertSeeInOrder([
            'id="tile-case-file"', 'href="'.route('public.game.line-up').'" id="tile-line-up"',
            versioned_asset('modules/game/tile-line-up.svg'), '<span class="library-tile-name">Line-Up</span>', 'id="tile-live"',
            'id="game-line-up"', versioned_asset('modules/game/picture-line-up.svg'),
            '10 rounds', 'Each five districts of one state, one crime and one year, and 30 seconds to line them up.',
            'Tap two to swap', 'Close counts', '200 points for each in its place, 100 if it&#039;s one place off: up to 1,000 a round.',
        ], false);
    }

    public function test_without_enough_districts_there_is_no_game(): void
    {
        // Four districts a state, one short.
        $this->figures(districts: 4);

        $this->get('/public/game/line-up')->assertOk()->assertSee('There are no crime figures to play with yet.')->assertDontSee('data-line-up ', false);
        $this->postJson('/public/game/line-up/start', ['name' => 'Siti'])->assertStatus(503);
        $this->assertDatabaseCount('LineUpRuns', 0);
    }

    public function test_districts_too_close_to_tell_apart_are_not_a_line_up(): void
    {
        // Five districts a state, their counts all within 10% of each other: no way to pick five far enough apart.
        foreach (['Johor' => 'MY-01', 'Perak' => 'MY-08'] as $state => $region) {
            foreach (range(1, 5) as $n) {
                PoliceDistrict::create(['State' => $state, 'Name' => "{$state} {$n}", 'Region' => $region, 'Latitude' => 2, 'Longitude' => 101]);
                CrimeStat::create(['State' => $state, 'District' => "{$state} {$n}", 'Category' => 'property', 'Type' => 'break_in', 'Year' => 2023, 'Crimes' => 100 + $n]);
            }
        }

        $this->assertNull(app(LineUp::class)->deal(1));
        $this->assertFalse(app(LineUp::class)->ready());
    }

    public function test_a_game_is_ten_rounds_and_the_counts_stay_on_the_server_until_its_order(): void
    {
        $this->figures();
        $this->freezeSecond();

        $started = $this->postJson('/public/game/line-up/start', ['name' => '  Siti  Aminah '])->assertOk()
            ->assertJson(['rounds' => 10, 'seconds' => 30, 'ready' => 3, 'asked' => ['round' => 1]])
            ->json();

        $run = LineUpRun::sole();
        $this->assertSame([$run->Token, 'Siti Aminah', 'siti aminah', 0, []], [$started['token'], $run->PlayerName, $run->PlayerKey, $run->Score, $run->Orders]);
        // Its time starts after the countdown.
        $this->assertTrue($run->AskedAt->equalTo(now()->addSeconds(3)));

        // Ten rounds, none the same state, crime and year; each five districts of its state, each at least 1.1 times
        // the next, standing in no order, each with a look of its own.
        $this->assertCount(10, $run->Dealt);
        $this->assertCount(10, array_unique(array_map(fn (array $round) => "{$round['state']}/{$round['type']}/{$round['year']}", $run->Dealt)));
        foreach ($run->Dealt as $round) {
            $this->assertCount(5, $round['suspects']);
            $counts = array_column($round['suspects'], 'crimes');
            $sorted = $counts;
            rsort($sorted);
            foreach (range(0, 3) as $i) {
                $this->assertGreaterThanOrEqual($sorted[$i + 1] * 1.1, $sorted[$i]);
            }
            $this->assertNotSame($sorted, $counts);
            $this->assertCount(5, array_unique(array_column($round['suspects'], 'look')));
            $this->assertEmpty(array_diff(array_column($round['suspects'], 'look'), LineUp::Looks));
            foreach ($round['suspects'] as $suspect) {
                $this->assertStringStartsWith($round['state'], $suspect['name']);
            }
        }

        // What the page has: the state, its region, the crime and year, and each suspect with its letter, name, look
        // and where it is, but not its count.
        $first = $run->Dealt[0];
        $asked = $started['asked'];
        $this->assertSame(['round', 'state', 'regions', 'type', 'icon', 'year', 'suspects'], array_keys($asked));
        $this->assertSame([$first['state'], [$asked['regions'][0]], $first['year']], [$asked['state'], $asked['regions'], $asked['year']]);
        $this->assertSame($first['type'] === 'break_in' ? 'Break-in' : 'Other theft', $asked['type']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_column($asked['suspects'], 'letter'));
        $this->assertSame(array_column($first['suspects'], 'name'), array_column($asked['suspects'], 'name'));
        $this->assertSame(['letter', 'name', 'look', 'lat', 'lng'], array_keys($asked['suspects'][0]));
        $this->assertStringNotContainsString('crimes', json_encode($asked));

        $this->travel(3)->seconds();
        $this->order($started['token'], LineUp::truth($first))->assertOk();
        $this->postJson('/public/game/line-up/next', ['token' => $started['token']])->assertOk()
            ->assertJson(['asked' => ['round' => 2, 'state' => $run->Dealt[1]['state']]])
            ->assertJsonMissingPath('asked.suspects.0.crimes');
    }

    public function test_an_order_scores_each_suspect_in_its_place_or_one_off(): void
    {
        $round = ['suspects' => array_map(fn (int $crimes) => ['crimes' => $crimes], [30, 100, 10, 60, 20])];

        // Most first: the second (100), the fourth (60), the first (30), the fifth (20), the third (10).
        $this->assertSame([1, 3, 0, 4, 2], LineUp::truth($round));
        $this->assertSame(1000, LineUp::points([1, 3, 0, 4, 2], $round));
        $this->assertSame([0, 0, 0, 0, 0], LineUp::offs([1, 3, 0, 4, 2], $round));
        // Two next to each other swapped: three in place, two one off.
        $this->assertSame(800, LineUp::points([3, 1, 0, 4, 2], $round));
        // As they first stood: the second and the fifth one place off, the rest two.
        $this->assertSame([2, 1, 2, 2, 1], LineUp::offs([0, 1, 2, 3, 4], $round));
        $this->assertSame(200, LineUp::points([0, 1, 2, 3, 4], $round));
        // Back to front: only the middle one in its place.
        $this->assertSame(200, LineUp::points([2, 4, 0, 3, 1], $round));
        $this->assertSame(0, LineUp::points(null, $round));
        $this->assertNull(LineUp::offs(null, $round));
        $this->assertSame(1000, LineUp::most());
        $this->assertTrue(LineUp::valid([4, 0, 3, 1, 2]));
        $this->assertFalse(LineUp::valid([0, 0, 1, 2, 3]));
        $this->assertFalse(LineUp::valid([0, 1, 2, 3]));
    }

    public function test_an_order_gives_its_points_the_right_order_and_every_count(): void
    {
        $this->figures();
        $token = $this->start();

        // Right: every point, the right order, each count, and none off.
        $round = $this->asked();
        $truth = LineUp::truth($round);
        $this->order($token, $truth)->assertOk()->assertJson([
            'round' => 1, 'late' => false, 'order' => $truth, 'truth' => $truth, 'crimes' => array_column($round['suspects'], 'crimes'),
            'offs' => [0, 0, 0, 0, 0], 'points' => 1000, 'score' => 1000, 'over' => false, 'rounds' => null,
        ]);

        // The first two swapped: 800.
        $this->postJson('/public/game/line-up/next', ['token' => $token]);
        $truth = LineUp::truth($this->asked());
        [$truth[0], $truth[1]] = [$truth[1], $truth[0]];
        $this->order($token, $truth)->assertOk()->assertJson(['round' => 2, 'points' => 800, 'score' => 1800]);

        $run = LineUpRun::sole();
        $this->assertSame([1000, 800], array_column($run->Orders, 'points'));
        $this->assertSame($truth, $run->Orders[1]['order']);
        $this->assertNotNull($run->ScoredAt);
    }

    public function test_no_order_or_a_late_or_wrong_one_scores_nothing(): void
    {
        $this->figures();
        $this->freezeSecond();
        $token = $this->start();

        // The time ran out without a suspect moved: no order, and the right one shown.
        $this->order($token, null)->assertOk()->assertJson(['late' => true, 'order' => null, 'offs' => null, 'points' => 0, 'score' => 0]);

        // An order, but after the 30 seconds and the 4 more allowed for it to arrive.
        $this->postJson('/public/game/line-up/next', ['token' => $token]);
        $this->travel(35)->seconds();
        $this->order($token, LineUp::truth($this->asked()))->assertOk()->assertJson(['late' => true, 'points' => 0]);

        // Within them, it counts.
        $this->postJson('/public/game/line-up/next', ['token' => $token]);
        $this->travel(33)->seconds();
        $this->order($token, LineUp::truth($this->asked()))->assertOk()->assertJson(['late' => false, 'points' => 1000]);

        // Once a round has its order, not again; and an order is each of the five once.
        $this->order($token, [0, 1, 2, 3, 4])->assertStatus(409)->assertJsonPath('message', 'This round has had its line-up. Go on to the next one.');
        $this->postJson('/public/game/line-up/next', ['token' => $token]);
        $this->order($token, [0, 0, 1, 2, 3])->assertUnprocessable();
        $this->order($token, [0, 1, 2, 3])->assertUnprocessable();
        $this->order($token, [0, 1, 2, 3, 5])->assertUnprocessable();
        $this->postJson('/public/game/line-up/order', ['token' => $token, 'order' => 'tall'])->assertUnprocessable();
    }

    public function test_the_rounds_go_in_order_and_the_game_ends_after_ten_with_its_statement(): void
    {
        $this->figures();
        $token = $this->start();

        $this->postJson('/public/game/line-up/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This round is still waiting for its line-up.');

        foreach (range(1, 10) as $round) {
            $order = $round === 4 ? null : LineUp::truth($this->asked());
            $last = $this->order($token, $order)->assertOk()->assertJsonPath('round', $round);

            if ($round < 10) {
                $last->assertJsonPath('over', false)->assertJsonPath('rounds', null);
                $this->postJson('/public/game/line-up/next', ['token' => $token])->assertOk();
            }
        }

        $last->assertJson(['over' => true, 'score' => 9000]);
        $rounds = $last->json('rounds');
        $this->assertCount(10, $rounds);
        $this->assertSame(['type', 'state', 'year', 'points', 'suspects'], array_keys($rounds[0]));
        // Each round's suspects in the right order, most first, each with how far off it stood: none without an order.
        $this->assertSame(['name', 'crimes', 'off'], array_keys($rounds[0]['suspects'][0]));
        $crimes = array_column($rounds[0]['suspects'], 'crimes');
        $sorted = $crimes;
        rsort($sorted);
        $this->assertSame($sorted, $crimes);
        $this->assertSame([0, 0, 0, 0, 0], array_column($rounds[0]['suspects'], 'off'));
        $this->assertSame([null, null, null, null, null], array_column($rounds[3]['suspects'], 'off'));
        $this->assertSame(0, $rounds[3]['points']);
        $this->assertNotNull(LineUpRun::sole()->EndedAt);

        $this->postJson('/public/game/line-up/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This game has ended. Start a new one.');
        $this->postJson('/public/game/line-up/next', ['token' => 'made-up'])->assertNotFound();
    }

    public function test_game_scores_shows_line_up_and_its_winner(): void
    {
        $this->figures();
        $me = $this->signIn();
        $started = now()->subHour();
        $run = LineUpRun::create([
            'Token' => 'a-token', 'PlayerName' => 'Muthu', 'PlayerKey' => 'muthu', 'Score' => 7400, 'Dealt' => [], 'Orders' => [],
            'ScoredAt' => $started->copy()->addMinutes(4), 'StartedAt' => $started, 'EndedAt' => $started->copy()->addMinutes(4),
        ]);

        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['aria-label="Games"', 'Skyline', 'Case File', 'Line-Up'], false)
            ->assertDontSee('Muthu');

        // Its board hosts a live game of it to start with.
        $this->get('/game-scores?game=line-up')->assertOk()
            ->assertSee('Line-Up leaderboard')
            ->assertSeeInOrder(['Muthu', '<td class="scores-score">7400</td>', '4 min'], false)
            ->assertSee('<option value="line-up" selected>Line-Up</option>', false);

        $this->post('/game-scores/store-winner', ['game' => 'line-up', 'run' => $run->Id])
            ->assertRedirect(route('game-scores', ['game' => 'line-up']))
            ->assertSessionHas('message', 'Chose Muthu as the winner, with a score of 7400.');
        $winner = GameWinner::sole();
        $this->assertSame(['line-up', $run->Id, 'Muthu', $me->Id], [$winner->Game, $winner->GameRunId, $winner->PlayerName, $winner->ChosenById]);
    }

    public function test_the_game_comes_in_bahasa_melayu_under_the_same_name(): void
    {
        $this->figures();

        $this->get('/public/game/line-up?lang=ms')->assertOk()
            ->assertSee('<title>Line-Up · '.config('app.name').'</title>', false)
            ->assertSee('data-start="'.route('public.game.line-up.start', ['lang' => 'ms']).'"', false)
            ->assertSeeInOrder(['Arahan saksi', 'kawad cam', 'Saksi', 'Bawa mereka masuk', 'Sahkan susunan', 'Keterangan saksi'])
            ->assertSee('":name picked up. Tap where it goes, or use the arrow keys.":":name diangkat.', false);

        // The crime in the player's language.
        $asked = $this->postJson('/public/game/line-up/start?lang=ms', ['name' => 'Siti'])->assertOk()->json('asked');
        $this->assertContains($asked['type'], [__('Break-in', [], 'ms'), __('Other theft', [], 'ms')]);
        $this->assertNotContains($asked['type'], ['Break-in', 'Other theft']);

        $this->postJson('/public/game/line-up/next?lang=ms', ['token' => 'made-up'])
            ->assertJsonPath('message', 'Permainan ini tidak dijumpai. Mulakan yang baharu.');
    }

    /**
     * A live game of Line-Up hosted by an administrator, from the Game scores page.
     */
    private function hosted(int $rounds = 3): LiveGame
    {
        $this->figures();
        $this->signIn();
        $this->post('/game-scores/store-live', ['game' => 'line-up', 'rounds' => $rounds])->assertRedirect();

        return LiveGame::query()->latest('Id')->firstOrFail();
    }

    /**
     * @return array{player: int, token: string}
     */
    private function join(LiveGame $game, string $name): array
    {
        return $this->postJson('/api/game/live/join', ['code' => $game->Code, 'name' => $name])->assertOk()->json();
    }

    /**
     * @param  array{player: int, token: string}  $player
     */
    private function check(LiveGame $game, array $player): TestResponse
    {
        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->getJson("/api/game/live/{$game->Id}");
    }

    /**
     * An order for the round being played: the right one, or the one given.
     *
     * @param  array{player: int, token: string}  $player
     * @param  list<int>|null  $order
     */
    private function lineUp(LiveGame $game, array $player, ?array $order = null): TestResponse
    {
        $game->refresh();

        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => $game->Round, 'order' => $order ?? LineUp::truth($game->Cards[$game->Round - 1])]);
    }

    private function host(LiveGame $game, string $action = 'state'): TestResponse
    {
        return $action === 'state'
            ? $this->getJson("/game-scores/live-state/{$game->Id}")
            : $this->postJson("/game-scores/live-{$action}/{$game->Id}");
    }

    public function test_an_administrator_hosts_a_live_game_of_line_up(): void
    {
        $this->freezeTime();
        $game = $this->hosted();

        $this->assertSame(['line-up', 'classic', LiveGame::Lobby, 3, 30], [$game->Game, $game->Mode, $game->Status, $game->Rounds, $game->Seconds]);
        $this->assertCount(3, $game->Cards);
        $this->assertSame(['state', 'regions', 'category', 'type', 'year', 'suspects'], array_keys($game->Cards[0]));

        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['<select id="live-game" name="game">', '<option value="case-file"', '<option value="line-up"', 'Line-Up'], false)
            ->assertSeeInOrder(['Live games', $game->spacedCode(), 'Line-Up', '3, not started'], false);

        // Its big screen has the room and the game's title, without a card.
        $this->get("/game-scores/live/{$game->Id}")->assertOk()
            ->assertSee('alt="Line-Up" lang="en"', false)
            ->assertSee('data-line-up="'.versioned_asset(config('map.boundaries')).'"', false)
            ->assertSee('data-lu-room data-mode="host"', false)
            // Nothing to press on the big screen.
            ->assertDontSee('data-lu="console"', false)
            ->assertSee('<symbol id="lu-cap"', false)
            ->assertSee(versioned_asset('modules/game/line-up-room.js'), false)
            ->assertSeeInOrder(['3 rounds of', '30 seconds each, and', 'the more of the line-up in its right order, most crime first, and the sooner, the more points.'], false)
            ->assertDontSee('data-game-card', false);
    }

    public function test_line_up_rounds_take_orders_and_score_them_and_their_speed(): void
    {
        $this->freezeTime();
        $game = $this->hosted();
        $players = collect(['Siti', 'Ali', 'Chong', 'Devi'])->mapWithKeys(fn (string $name) => [$name => $this->join($game, $name)]);

        // Everyone sees the suspects, but not their counts or the right order until the answer.
        $this->host($game, 'next')->assertJson(['game' => 'line-up', 'phase' => 'ready', 'round' => 1, 'seconds' => 30]);
        $round = $game->fresh()->Cards[0];
        $truth = LineUp::truth($round);
        $this->check($game, $players['Siti'])
            ->assertJson(['game' => 'line-up', 'phase' => 'ready', 'lineup' => ['state' => $round['state']], 'guess' => null])
            ->assertJsonMissingPath('answer')->assertJsonMissingPath('crimes')->assertJsonMissingPath('lineup.suspects.0.crimes');
        $this->lineUp($game, $players['Siti'])->assertStatus(409);

        $this->travel(3)->seconds();
        $this->host($game)->assertJson(['phase' => 'question', 'lineup' => ['state' => $round['state']]])->assertJsonMissingPath('answer');

        // Right, straight away: all the points, and the phone keeps the order.
        $this->lineUp($game, $players['Siti'])->assertOk()->assertExactJson(['received' => true]);
        $this->check($game, $players['Siti'])->assertJson(['phase' => 'question', 'guess' => $truth]);

        // An order is each of the five once.
        $this->lineUp($game, $players['Ali'], [0, 0, 1, 2, 3])->assertUnprocessable()->assertJsonValidationErrors('order.1');
        $this->withHeaders(['X-Player' => $players['Ali']['player'], 'X-Player-Token' => $players['Ali']['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => 1, 'guess' => 'higher'])->assertUnprocessable()->assertJsonValidationErrors('order');

        // The first two swapped (800), halfway through the time: an eighth fewer. Right at the last moment: a quarter
        // fewer. After it, too late.
        $swapped = $truth;
        [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];
        $this->travel(15)->seconds();
        $this->lineUp($game, $players['Ali'], $swapped)->assertOk();
        $this->travel(15)->seconds();
        $this->lineUp($game, $players['Chong'])->assertOk();
        $this->travel(1001)->milliseconds();
        $this->lineUp($game, $players['Devi'])->assertStatus(409);

        $this->assertSame(
            ['Siti' => 1000, 'Ali' => 700, 'Chong' => 750, 'Devi' => 0],
            $game->players()->orderBy('Id')->pluck('Score', 'PlayerName')->all(),
        );

        // At the answer: the right order, every count, and the order given.
        $crimes = array_column($round['suspects'], 'crimes');
        $this->check($game, $players['Ali'])->assertJson([
            'phase' => 'reveal', 'answer' => $truth, 'crimes' => $crimes, 'guess' => $swapped, 'result' => ['correct' => true, 'points' => 700],
        ]);
        $this->check($game, $players['Devi'])->assertJson(['phase' => 'reveal', 'answer' => $truth, 'result' => null, 'guess' => null, 'rank' => 4]);

        // The big screen: the right order, how many had each in its place, and how many had them all.
        $placed = array_fill(0, 5, 3);
        $placed[$truth[0]] = 2;
        $placed[$truth[1]] = 2;
        $this->host($game)->assertJson(['phase' => 'reveal', 'answer' => $truth, 'crimes' => $crimes, 'placed' => $placed, 'perfect' => 2, 'answered' => 3, 'last' => false])
            ->assertJsonMissingPath('said')->assertJsonMissingPath('guesses');
    }

    public function test_the_phone_page_is_ready_for_line_up(): void
    {
        $this->get('/public/game/live')->assertOk()
            ->assertSee('<div data-live-game="line-up" hidden>', false)
            ->assertSee('<symbol id="lu-cap"', false)
            ->assertSee('Put them in order for the crime and year shown, most on the left. Tap two to swap them, or drag one along the line.')
            ->assertSee('The sooner you confirm, the more of them you keep: 75% at the last moment.')
            // Confirm along the foot of the room.
            ->assertSeeInOrder(['data-live-game="line-up" data-live="lineup"', 'data-lu-room data-mode="phone"', 'data-lu="floor"', 'data-lu="console"', 'data-lu="confirm"'], false)
            ->assertSeeInOrder([versioned_asset('modules/game/line-up-room.js'), versioned_asset('modules/game/live.js')], false);
    }
}
