<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Game\Entities\GameWinner;
use Modules\Game\Entities\LiveGame;
use Modules\Game\Entities\SkylineRun;
use Modules\Game\Support\Skyline;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class SkylineTest extends TestCase
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
     * District n's count is 10n, a little more for other thefts and for 2023, so the last is the tallest.
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
        return $this->postJson('/public/game/skyline/start', ['name' => 'Siti'])->assertOk()->json('token');
    }

    /**
     * The count of the tower being guessed, from the game kept on the server.
     */
    private function answer(): int
    {
        $run = SkylineRun::query()->latest('Id')->firstOrFail();

        return $run->Dealt[$run->round() - 1]['crimes'];
    }

    private function guess(string $token, ?int $guess): TestResponse
    {
        return $this->postJson('/public/game/skyline/guess', ['token' => $token, 'guess' => $guess]);
    }

    public function test_the_game_has_its_page_and_its_tile_on_the_games_page(): void
    {
        $this->figures();

        $this->get('/public/game/skyline')->assertOk()
            ->assertSee('<title>Skyline · '.config('app.name').'</title>', false)
            ->assertSee('alt="Skyline" lang="en"', false)
            ->assertSee('data-start="'.route('public.game.skyline.start').'"', false)
            ->assertSee('data-boundaries="'.versioned_asset('modules/map/malaysia-states.geojson').'"', false)
            ->assertSee('data-seconds="30" data-rounds="10" data-points="1000"', false)
            ->assertSee('One tower is a glass outline: raise it with the slider to how tall you think it is, then press Confirm. 30 seconds a round.')
            ->assertSee('Up to 1,000 points a round, all of them within 2%: the closer, the more.')
            // Three.js and the scene by name, each with its version, before the scripts that load them.
            ->assertSeeInOrder([
                '<script type="importmap">',
                '"three":"'.versioned_asset('vendor/three/three.module.min.js').'"',
                '"three/addons/controls/OrbitControls.js":"'.versioned_asset('vendor/three/addons/controls/OrbitControls.js').'"',
                '"crimeify/skyline-scene":"'.versioned_asset('modules/game/skyline-scene.js').'"',
                versioned_asset('modules/game/skyline-round.js'), versioned_asset('modules/game/skyline.js'),
            ], false)
            ->assertSeeInOrder(['How tall is', 'data-sky="scene"', 'data-sky="slider"', 'data-sky="confirm"', 'Every district&#039;s count'], false)
            ->assertSee('<a href="'.route('public.game').'" class="public-link active" aria-current="page">', false);

        // On the Games page, after DistrictGuessr.
        $this->get('/public/game')->assertSeeInOrder([
            'id="tile-districtguessr"', 'href="'.route('public.game.skyline').'" id="tile-skyline"',
            versioned_asset('modules/game/tile-skyline.svg'), '<span class="library-tile-name">Skyline</span>', 'id="tile-live"',
            'id="game-skyline"', 'alt="Skyline"', versioned_asset('modules/game/city-skyline.svg'),
            '10 rounds', 'Each with one crime and one year, and 30 seconds to judge the hidden tower.', 'Up to 1,000 points a round: all of them within 2%.',
        ], false);
    }

    public function test_without_enough_districts_there_is_no_game(): void
    {
        // Four districts a state, one short.
        $this->figures(districts: 4);

        $this->get('/public/game/skyline')->assertOk()->assertSee('There are no crime figures to play with yet.')->assertDontSee('data-skyline ', false);
        $this->postJson('/public/game/skyline/start', ['name' => 'Siti'])->assertStatus(503);
        $this->assertDatabaseCount('SkylineRuns', 0);
    }

    public function test_a_game_is_ten_rounds_and_the_count_to_guess_stays_on_the_server_until_its_guess(): void
    {
        $this->figures();
        $this->freezeSecond();

        $started = $this->postJson('/public/game/skyline/start', ['name' => '  Siti  Aminah '])->assertOk()
            ->assertJson(['rounds' => 10, 'seconds' => 30, 'ready' => 3, 'asked' => ['round' => 1]])
            ->assertJsonMissingPath('asked.crimes')
            ->json();

        $run = SkylineRun::sole();
        $this->assertSame([$run->Token, 'Siti Aminah', 'siti aminah', 0, []], [$started['token'], $run->PlayerName, $run->PlayerKey, $run->Score, $run->Guesses]);
        // Its time starts after the countdown.
        $this->assertTrue($run->AskedAt->equalTo(now()->addSeconds(3)));

        // Ten rounds, none the same state, crime and year; each with all its state's districts, and one to guess that
        // isn't the tallest.
        $this->assertCount(10, $run->Dealt);
        $this->assertCount(10, array_unique(array_map(fn (array $round) => "{$round['state']}/{$round['type']}/{$round['year']}", $run->Dealt)));
        foreach ($run->Dealt as $round) {
            $counts = array_column($round['towers'], 'crimes', 'name');
            $this->assertCount(6, $counts);
            $this->assertSame($counts[$round['target']], $round['crimes']);
            $this->assertLessThan(max($counts), $round['crimes']);
            $this->assertStringStartsWith($round['state'], $round['target']);
        }

        // What the page has: the state, its region, the crime and year, every tower, the one to guess without its count,
        // the tallest, and how high a guess can go.
        $first = $run->Dealt[0];
        $asked = $started['asked'];
        $tallest = max(array_column($first['towers'], 'crimes'));
        $this->assertSame([$first['state'], [$asked['regions'][0]], $first['target'], $first['year']], [$asked['state'], $asked['regions'], $asked['target'], $asked['year']]);
        $this->assertSame(['round', 'state', 'regions', 'type', 'icon', 'year', 'target', 'towers', 'tallest', 'most'], array_keys($asked));
        $this->assertSame($first['type'] === 'break_in' ? 'Break-in' : 'Other theft', $asked['type']);
        $this->assertSame(['name' => "{$first['state']} 6", 'crimes' => $tallest], $asked['tallest']);
        $this->assertSame((int) ceil($tallest * 1.5), $asked['most']);
        $this->assertNull(collect($asked['towers'])->firstWhere('name', $first['target'])['crimes']);
        $this->assertSame(5, collect($asked['towers'])->whereNotNull('crimes')->count());
        $this->assertStringNotContainsString('"crimes":'.$first['crimes'].',', json_encode($asked));

        $this->travel(3)->seconds();
        $this->guess($started['token'], $this->answer())->assertOk();
        $this->postJson('/public/game/skyline/next', ['token' => $started['token']])->assertOk()
            ->assertJson(['asked' => ['round' => 2, 'target' => $run->Dealt[1]['target']]])
            ->assertJsonMissingPath('asked.crimes');
    }

    public function test_a_guess_scores_by_how_close_it_is(): void
    {
        $this->figures();
        $token = $this->start();

        // Right on it: every point, and what it was.
        $crimes = $this->answer();
        $this->guess($token, $crimes)->assertOk()
            ->assertJson(['round' => 1, 'late' => false, 'guess' => $crimes, 'crimes' => $crimes, 'points' => 1000, 'score' => 1000, 'over' => false]);

        // Half as much again: about a third.
        $this->postJson('/public/game/skyline/next', ['token' => $token]);
        $crimes = $this->answer();
        $points = Skyline::points((int) round($crimes * 1.5), $crimes);
        $this->guess($token, (int) round($crimes * 1.5))->assertOk()->assertJson(['round' => 2, 'points' => $points, 'score' => 1000 + $points]);

        // No higher than the round's most.
        $this->postJson('/public/game/skyline/next', ['token' => $token]);
        $most = Skyline::most(SkylineRun::sole()->Dealt[2]);
        $this->guess($token, 999999)->assertOk()->assertJson(['guess' => $most]);

        $run = SkylineRun::sole();
        $this->assertSame([1000, $points], array_slice(array_column($run->Guesses, 'points'), 0, 2));
        $this->assertNotNull($run->ScoredAt);
    }

    public function test_the_points_fall_away_with_how_far_off_it_is(): void
    {
        $this->assertSame(1000, Skyline::points(100, 100));
        // Within 2%, all of them.
        $this->assertSame(1000, Skyline::points(102, 100));
        $this->assertSame(1000, Skyline::points(98, 100));
        $this->assertSame(819, Skyline::points(110, 100));
        $this->assertSame(819, Skyline::points(90, 100));
        $this->assertSame(368, Skyline::points(150, 100));
        $this->assertSame(135, Skyline::points(0, 100));
        $this->assertSame(18, Skyline::points(300, 100));
        $this->assertSame(0, Skyline::points(null, 100));
    }

    public function test_no_guess_or_a_late_one_scores_nothing(): void
    {
        $this->figures();
        $this->freezeSecond();
        $token = $this->start();

        // The time ran out without the tower moved.
        $this->guess($token, null)->assertOk()->assertJson(['late' => true, 'guess' => null, 'points' => 0, 'score' => 0]);

        // A guess, but after the 30 seconds and the 4 more allowed for it to arrive.
        $this->postJson('/public/game/skyline/next', ['token' => $token]);
        $this->travel(35)->seconds();
        $this->guess($token, $this->answer())->assertOk()->assertJson(['late' => true, 'points' => 0]);

        // Within them, it counts.
        $this->postJson('/public/game/skyline/next', ['token' => $token]);
        $this->travel(33)->seconds();
        $this->guess($token, $this->answer())->assertOk()->assertJson(['late' => false, 'points' => 1000]);

        // Once a round has its guess, not again; and a guess is a count.
        $this->guess($token, 10)->assertStatus(409)->assertJsonPath('message', 'This round has had its guess. Go on to the next one.');
        $this->postJson('/public/game/skyline/next', ['token' => $token]);
        $this->guess($token, -1)->assertUnprocessable();
        $this->postJson('/public/game/skyline/guess', ['token' => $token, 'guess' => 'tall'])->assertUnprocessable();
    }

    public function test_the_rounds_go_in_order_and_the_game_ends_after_ten(): void
    {
        $this->figures();
        $token = $this->start();

        $this->postJson('/public/game/skyline/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This round is still waiting for its guess.');

        foreach (range(1, 10) as $round) {
            $last = $this->guess($token, $this->answer())->assertOk()->assertJsonPath('round', $round);

            if ($round < 10) {
                $last->assertJsonPath('over', false)->assertJsonPath('rounds', null);
                $this->postJson('/public/game/skyline/next', ['token' => $token])->assertOk();
            }
        }

        $last->assertJson(['over' => true, 'score' => 10000]);
        $this->assertCount(10, $last->json('rounds'));
        $this->assertSame(['district', 'state', 'crimes', 'guess', 'points'], array_keys($last->json('rounds.0')));
        $this->assertNotNull(SkylineRun::sole()->EndedAt);

        $this->postJson('/public/game/skyline/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This game has ended. Start a new one.');
        $this->postJson('/public/game/skyline/next', ['token' => 'made-up'])->assertNotFound();
    }

    public function test_a_states_outline_is_every_region_its_districts_are_in(): void
    {
        // Kuala Lumpur's police take in Putrajaya.
        $this->figures(['W.P. Kuala Lumpur' => 'MY-14']);
        PoliceDistrict::query()->where('Name', 'W.P. Kuala Lumpur 6')->update(['Region' => 'MY-16']);

        $round = app(Skyline::class)->deal(1)[0];

        $this->assertSame(['MY-14', 'MY-16'], $round['regions']);
    }

    public function test_game_scores_shows_skyline_and_its_winner(): void
    {
        $this->figures();
        $me = $this->signIn();
        $started = now()->subHour();
        $run = SkylineRun::create([
            'Token' => 'a-token', 'PlayerName' => 'Muthu', 'PlayerKey' => 'muthu', 'Score' => 8125, 'Dealt' => [], 'Guesses' => [],
            'ScoredAt' => $started->copy()->addMinutes(5), 'StartedAt' => $started, 'EndedAt' => $started->copy()->addMinutes(5),
        ]);

        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['aria-label="Games"', 'Higher or Lower', 'DistrictGuessr Easy', 'DistrictGuessr Hard', 'Skyline'], false)
            ->assertDontSee('Muthu');

        // Its board hosts a live game of it to start with, without a mode or a level.
        $this->get('/game-scores?game=skyline')->assertOk()
            ->assertSee('Skyline leaderboard')
            ->assertSeeInOrder(['Muthu', '<td class="scores-score">8125</td>', '5 min'], false)
            ->assertSee('<option value="skyline" selected>Skyline</option>', false)
            ->assertSeeInOrder(['<div data-live-for="higher-or-lower" class="is-other">', '<div data-live-for="districtguessr" class="is-other">'], false);

        $this->post('/game-scores/store-winner', ['game' => 'skyline', 'run' => $run->Id])
            ->assertRedirect(route('game-scores', ['game' => 'skyline']))
            ->assertSessionHas('message', 'Chose Muthu as the winner, with a score of 8125.');
        $winner = GameWinner::sole();
        $this->assertSame(['skyline', $run->Id, 'Muthu', $me->Id], [$winner->Game, $winner->GameRunId, $winner->PlayerName, $winner->ChosenById]);
        $this->get('/game-scores')->assertSeeInOrder(['id="winners-heading"', 'Muthu', 'Skyline', '8125'], false);
    }

    public function test_the_game_comes_in_bahasa_melayu_under_the_same_name(): void
    {
        $this->figures();

        $this->get('/public/game/skyline?lang=ms')->assertOk()
            ->assertSee('<title>Skyline · '.config('app.name').'</title>', false)
            ->assertSee('data-start="'.route('public.game.skyline.start', ['lang' => 'ms']).'"', false)
            ->assertSeeInOrder(['Cara bermain', 'Satu menara hanya rangka kaca', 'Berapa tinggi', 'Tekaan anda', 'Sahkan', 'Pusingan seterusnya'])
            ->assertSee('"Guess: :count":"Tekaan: :count"', false);

        // The crime in the player's language.
        $asked = $this->postJson('/public/game/skyline/start?lang=ms', ['name' => 'Siti'])->assertOk()->json('asked');
        $this->assertContains($asked['type'], [__('Break-in', [], 'ms'), __('Other theft', [], 'ms')]);
        $this->assertNotContains($asked['type'], ['Break-in', 'Other theft']);

        $this->postJson('/public/game/skyline/next?lang=ms', ['token' => 'made-up'])
            ->assertJsonPath('message', 'Permainan ini tidak dijumpai. Mulakan yang baharu.');
    }

    /**
     * A live game of Skyline hosted by an administrator, from the Game scores page.
     */
    private function hosted(int $rounds = 3): LiveGame
    {
        $this->figures();
        $this->signIn();
        $this->post('/game-scores/store-live', ['game' => 'skyline', 'rounds' => $rounds])->assertRedirect();

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
    private function check(LiveGame $game, array $player, string $language = ''): TestResponse
    {
        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->getJson("/api/game/live/{$game->Id}{$language}");
    }

    /**
     * A height for the round being played: the count, times $share.
     *
     * @param  array{player: int, token: string}  $player
     */
    private function raise(LiveGame $game, array $player, float $share = 1, ?int $value = null): TestResponse
    {
        $game->refresh();

        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => $game->Round, 'value' => $value ?? (int) round($game->Cards[$game->Round - 1]['crimes'] * $share)]);
    }

    private function host(LiveGame $game, string $action = 'state'): TestResponse
    {
        return $action === 'state'
            ? $this->getJson("/game-scores/live-state/{$game->Id}")
            : $this->postJson("/game-scores/live-{$action}/{$game->Id}");
    }

    public function test_an_administrator_hosts_a_live_game_of_skyline(): void
    {
        $this->freezeTime();
        $game = $this->hosted();

        $this->assertSame(['skyline', 'classic', LiveGame::Lobby, 3, 30], [$game->Game, $game->Mode, $game->Status, $game->Rounds, $game->Seconds]);
        $this->assertCount(3, $game->Cards);
        $this->assertSame(['state', 'regions', 'category', 'type', 'year', 'target', 'crimes', 'towers'], array_keys($game->Cards[0]));

        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['<select id="live-game" name="game">', '<option value="districtguessr"', '<option value="skyline"', 'Skyline'], false)
            ->assertSeeInOrder(['Live games', $game->spacedCode(), 'Skyline', '3, not started'], false);

        // Its big screen has the towers and the game's title, without a card.
        $this->get("/game-scores/live/{$game->Id}")->assertOk()
            ->assertSee('alt="Skyline" lang="en"', false)
            ->assertSee('data-skyline="'.versioned_asset(config('map.boundaries')).'"', false)
            ->assertSee('<script type="importmap">', false)
            ->assertSee(versioned_asset('modules/game/skyline-round.js'), false)
            ->assertSeeInOrder(['3 rounds of', '30 seconds each, and', 'the closer the guess at the hidden tower&#039;s height, and the sooner, the more points.'], false)
            ->assertDontSee('data-sky="slider"', false)
            ->assertDontSee('data-game-card', false)
            ->assertDontSee(versioned_asset('modules/game/guessr-map.js'), false);
    }

    public function test_skyline_rounds_take_heights_and_score_their_closeness_and_speed(): void
    {
        $this->freezeTime();
        $game = $this->hosted();
        $players = collect(['Siti', 'Ali', 'Chong', 'Devi'])->mapWithKeys(fn (string $name) => [$name => $this->join($game, $name)]);

        // Everyone sees the towers and the one to guess, but not its count until the answer.
        $this->host($game, 'next')->assertJson(['game' => 'skyline', 'phase' => 'ready', 'round' => 1, 'seconds' => 30]);
        $round = $game->fresh()->Cards[0];
        $this->check($game, $players['Siti'])
            ->assertJson(['game' => 'skyline', 'phase' => 'ready', 'skyline' => ['state' => $round['state'], 'target' => $round['target']], 'guess' => null])
            ->assertJsonMissingPath('answer')->assertJsonMissingPath('cards')->assertJsonMissingPath('district');
        $this->assertNull(collect($this->check($game, $players['Siti'])->json('skyline.towers'))->firstWhere('name', $round['target'])['crimes']);
        $this->raise($game, $players['Siti'])->assertStatus(409);

        $this->travel(3)->seconds();
        $this->host($game)->assertJson(['phase' => 'question', 'skyline' => ['target' => $round['target']]])->assertJsonMissingPath('answer');

        // Right on it, straight away: all the points, and the phone keeps the height.
        $this->raise($game, $players['Siti'])->assertOk()->assertExactJson(['received' => true]);
        $this->check($game, $players['Siti'])->assertJson(['phase' => 'question', 'guess' => $round['crimes']]);

        // A height is a count, not a guess or a pin.
        $this->withHeaders(['X-Player' => $players['Ali']['player'], 'X-Player-Token' => $players['Ali']['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => 1, 'guess' => 'higher'])->assertUnprocessable()->assertJsonValidationErrors('value');

        // Half as much again (368 points), halfway through the time: an eighth fewer. Right on it at the last moment: a
        // quarter fewer. After it, too late.
        $this->travel(15)->seconds();
        $this->raise($game, $players['Ali'], share: 1.5)->assertOk();
        $this->travel(15)->seconds();
        $this->raise($game, $players['Chong'])->assertOk();
        $this->travel(1001)->milliseconds();
        $this->raise($game, $players['Devi'])->assertStatus(409);

        $ali = (int) round(Skyline::points((int) round($round['crimes'] * 1.5), $round['crimes']) * 0.875);
        $this->assertSame(
            ['Siti' => 1000, 'Ali' => $ali, 'Chong' => 750, 'Devi' => 0],
            $game->players()->orderBy('Id')->pluck('Score', 'PlayerName')->all(),
        );

        $this->check($game, $players['Ali'])->assertJson([
            'phase' => 'reveal', 'answer' => $round['crimes'], 'guess' => (int) round($round['crimes'] * 1.5), 'result' => ['correct' => true, 'points' => $ali],
        ]);
        $this->check($game, $players['Devi'])->assertJson(['phase' => 'reveal', 'answer' => $round['crimes'], 'result' => null, 'guess' => null, 'rank' => 4]);

        // The big screen: what it was, and everyone's heights, the closest first.
        $reveal = $this->host($game)->assertJson(['phase' => 'reveal', 'answer' => $round['crimes'], 'last' => false])
            ->assertJsonMissingPath('said')->assertJsonMissingPath('pins')->json();
        $this->assertSame(
            [['Chong', $round['crimes'], 0, 750], ['Siti', $round['crimes'], 0, 1000], ['Ali', (int) round($round['crimes'] * 1.5), (int) round($round['crimes'] * 1.5) - $round['crimes'], $ali]],
            array_map(fn (array $guess) => [$guess['name'], $guess['value'], $guess['off'], $guess['points']], $reveal['guesses']),
        );

        // No higher than the round's most; and the next round's towers.
        $this->host($game, 'next')->assertJson(['phase' => 'ready', 'round' => 2, 'skyline' => ['target' => $game->fresh()->Cards[1]['target']]]);
        $this->travel(3)->seconds();
        $this->raise($game, $players['Siti'], value: 999999)->assertOk();
        $this->check($game, $players['Siti'])->assertJson(['guess' => Skyline::most($game->fresh()->Cards[1])]);
    }

    public function test_the_phone_page_is_ready_for_skyline(): void
    {
        $this->get('/public/game/live')->assertOk()
            ->assertSee('<div data-live-game="skyline" hidden>', false)
            ->assertSee('<script type="importmap">', false)
            ->assertSee('data-skyline-perfect="2"', false)
            ->assertSee('Up to 1,000 points a round, all of them within 2%: the closer, the more.')
            ->assertSeeInOrder(['data-live-game="skyline" data-live="skyline"', 'data-sky="slider"', 'data-sky="confirm"'], false)
            ->assertSeeInOrder([versioned_asset('modules/game/skyline-round.js'), versioned_asset('modules/game/live.js')], false);
    }
}
