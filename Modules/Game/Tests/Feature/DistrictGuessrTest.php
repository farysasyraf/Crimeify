<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Game\Entities\GameRun;
use Modules\Game\Entities\GameWinner;
use Modules\Game\Entities\GuessrRun;
use Modules\Game\Support\DistrictGuessr;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class DistrictGuessrTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Police districts in Johor, a tenth of a degree apart, enough for a game.
     */
    private function districts(int $count = 12): void
    {
        foreach (range(1, $count) as $n) {
            PoliceDistrict::create(['State' => 'Johor', 'Name' => "District {$n}", 'Region' => 'MY-01', 'Latitude' => 1.5 + $n / 10, 'Longitude' => 103 + $n / 10]);
        }
    }

    /**
     * Start a game as Siti: its token.
     */
    private function start(): string
    {
        return $this->postJson('/public/game/districtguessr/start', ['name' => 'Siti'])->assertOk()->json('token');
    }

    /**
     * Where the district being asked is, from the game kept on the server.
     *
     * @return array{float, float}
     */
    private function where(): array
    {
        $run = GuessrRun::query()->latest('Id')->firstOrFail();
        $district = $run->Districts[$run->round() - 1];

        return [$district['lat'], $district['lng']];
    }

    private function guess(string $token, ?float $lat, ?float $lng): TestResponse
    {
        return $this->postJson('/public/game/districtguessr/guess', ['token' => $token, 'lat' => $lat, 'lng' => $lng]);
    }

    public function test_the_game_has_its_page_and_its_tile_on_the_games_page(): void
    {
        $this->districts();

        $this->get('/public/game/districtguessr')->assertOk()
            ->assertSee('<title>DistrictGuessr · '.config('app.name').'</title>', false)
            ->assertSee('alt="DistrictGuessr" lang="en"', false)
            ->assertSee('data-start="'.route('public.game.districtguessr.start').'"', false)
            ->assertSee('data-boundaries="'.versioned_asset('modules/map/malaysia-states.geojson').'"', false)
            ->assertSee('data-seconds="40" data-rounds="10" data-points="1000"', false)
            ->assertSee('Tap the map to drop your pin, move it if you like, then press Confirm. 40 seconds a district.')
            ->assertSee('Up to 1,000 points a district, all of them within 2 km: the closer, the more.')
            ->assertSee(versioned_asset('vendor/leaflet/leaflet.js'), false)
            ->assertSee('<a href="'.route('public.game').'" class="public-link active" aria-current="page">', false);

        // On the Games page, after Higher or Lower.
        $this->get('/public/game')->assertSeeInOrder([
            'id="tile-higher-or-lower"', 'href="'.route('public.game.districtguessr').'" id="tile-districtguessr"',
            versioned_asset('modules/game/tile-districtguessr.svg'), '<span class="library-tile-name">DistrictGuessr</span>', 'id="tile-live"',
            'id="game-districtguessr"', 'alt="DistrictGuessr"', versioned_asset('modules/game/map-districtguessr.svg'),
            '10 districts', 'Each with its state as a hint, and 40 seconds to pin it.', 'No names on the map',
        ], false);
    }

    public function test_without_enough_districts_there_is_no_game(): void
    {
        $this->districts(9);

        $this->get('/public/game/districtguessr')->assertOk()->assertSee('There are no police districts to play with yet.')->assertDontSee('data-guessr ', false);
        $this->postJson('/public/game/districtguessr/start', ['name' => 'Siti'])->assertStatus(503);
        $this->assertDatabaseCount('GuessrRuns', 0);
    }

    public function test_a_game_is_ten_districts_and_where_each_is_stays_on_the_server_until_its_guess(): void
    {
        $this->districts();
        $this->freezeSecond();

        $started = $this->postJson('/public/game/districtguessr/start', ['name' => '  Siti  Aminah '])->assertOk()
            ->assertJson(['rounds' => 10, 'seconds' => 40, 'ready' => 3, 'district' => ['round' => 1, 'place' => 'Johor']])
            ->assertJsonMissingPath('district.lat')
            ->assertJsonMissingPath('district.lng')
            ->json();

        $run = GuessrRun::sole();
        $this->assertSame([$run->Token, 'Siti Aminah', 'siti aminah', 0, []], [$started['token'], $run->PlayerName, $run->PlayerKey, $run->Score, $run->Guesses]);
        $this->assertCount(10, array_unique(array_column($run->Districts, 'name')));
        $this->assertSame($run->Districts[0]['name'], $started['district']['name']);
        // Its time starts after the countdown.
        $this->assertTrue($run->AskedAt->equalTo(now()->addSeconds(3)));

        $this->guess($started['token'], ...$this->where())->assertOk();
        $this->postJson('/public/game/districtguessr/next', ['token' => $started['token']])->assertOk()
            ->assertJson(['district' => ['round' => 2, 'name' => $run->Districts[1]['name']]])
            ->assertJsonMissingPath('district.lat');
    }

    public function test_a_guess_scores_by_how_far_off_it_is(): void
    {
        $this->districts();
        $token = $this->start();

        // Right on it: every point.
        [$lat, $lng] = $this->where();
        $this->guess($token, $lat, $lng)->assertOk()
            ->assertJson(['round' => 1, 'late' => false, 'km' => 0, 'points' => 1000, 'score' => 1000, 'answer' => ['lat' => $lat, 'lng' => $lng], 'over' => false]);

        // A degree north, some 111 km: about a third.
        $this->postJson('/public/game/districtguessr/next', ['token' => $token]);
        [$lat, $lng] = $this->where();
        $this->guess($token, $lat + 1, $lng)->assertOk()
            ->assertJson(['round' => 2, 'km' => 111.2, 'points' => 336, 'score' => 1336]);

        $run = GuessrRun::sole();
        $this->assertSame([1336, [1000, 336]], [$run->Score, array_column($run->Guesses, 'points')]);
        $this->assertNotNull($run->ScoredAt);
    }

    public function test_the_points_fall_away_with_the_distance(): void
    {
        $this->assertSame(1000, DistrictGuessr::points(0));
        $this->assertSame(1000, DistrictGuessr::points(2));
        $this->assertSame(607, DistrictGuessr::points(52));
        $this->assertSame(375, DistrictGuessr::points(100));
        $this->assertSame(51, DistrictGuessr::points(300));
        $this->assertSame(0, DistrictGuessr::points(null));

        // Batu Pahat to Kluang, by their pins.
        $this->assertEqualsWithDelta(45.4, DistrictGuessr::km(1.85748, 102.93981, 2.03, 103.31), 0.1);
    }

    public function test_no_pin_or_a_late_one_scores_nothing(): void
    {
        $this->districts();
        $this->freezeSecond();
        $token = $this->start();

        // The time ran out without a pin.
        $this->guess($token, null, null)->assertOk()->assertJson(['late' => true, 'km' => null, 'points' => 0, 'score' => 0]);

        // A pin, but after the 40 seconds and the 4 more allowed for it to arrive.
        $this->postJson('/public/game/districtguessr/next', ['token' => $token]);
        $this->travel(45)->seconds();
        $this->guess($token, ...$this->where())->assertOk()->assertJson(['late' => true, 'points' => 0]);

        // Within them, it counts.
        $this->postJson('/public/game/districtguessr/next', ['token' => $token]);
        $this->travel(43)->seconds();
        $this->guess($token, ...$this->where())->assertOk()->assertJson(['late' => false, 'points' => 1000]);

        // Once a district has its guess, not again; and a guess must be a place.
        $this->guess($token, 2, 103)->assertStatus(409);
        $this->postJson('/public/game/districtguessr/next', ['token' => $token]);
        $this->guess($token, 91, 103)->assertUnprocessable();
        $this->guess($token, 2, null)->assertUnprocessable();
    }

    public function test_the_rounds_go_in_order_and_the_game_ends_after_ten(): void
    {
        $this->districts();
        $token = $this->start();

        $this->postJson('/public/game/districtguessr/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This district is still waiting for its guess.');

        foreach (range(1, 10) as $round) {
            $last = $this->guess($token, ...$this->where())->assertOk()->assertJsonPath('round', $round);
            $this->guess($token, 2, 103)->assertStatus(409);

            if ($round < 10) {
                $last->assertJsonPath('over', false)->assertJsonPath('rounds', null);
                $this->postJson('/public/game/districtguessr/next', ['token' => $token])->assertOk();
            }
        }

        $last->assertJson(['over' => true, 'score' => 10000]);
        $this->assertCount(10, $last->json('rounds'));
        $this->assertSame(['name', 'place', 'km', 'points'], array_keys($last->json('rounds.0')));
        $this->assertNotNull(GuessrRun::sole()->EndedAt);

        $this->postJson('/public/game/districtguessr/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This game has ended. Start a new one.');
        $this->postJson('/public/game/districtguessr/next', ['token' => 'made-up'])->assertNotFound();
    }

    public function test_game_scores_shows_either_game_and_its_winner(): void
    {
        $this->districts();
        $me = $this->signIn();
        $started = now()->subHour();
        $run = GuessrRun::create([
            'Token' => 'a-token', 'PlayerName' => 'Muthu', 'PlayerKey' => 'muthu', 'Score' => 7420, 'Districts' => [], 'Guesses' => [],
            'ScoredAt' => $started->copy()->addMinutes(4), 'StartedAt' => $started, 'EndedAt' => $started->copy()->addMinutes(4),
        ]);
        // A Higher or Lower game with the same Id, which isn't the winner.
        GameRun::create([
            'Token' => 'b-token', 'PlayerName' => 'Ali', 'PlayerKey' => 'ali', 'Score' => 5, 'Current' => [], 'Next' => [],
            'AskedAt' => $started, 'StartedAt' => $started,
        ]);

        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['aria-label="Games"', 'Higher or Lower', 'DistrictGuessr'], false)
            ->assertSee('Higher or Lower leaderboard')
            ->assertSee('Ali')
            ->assertDontSee('Muthu');

        $this->get('/game-scores?game=districtguessr')->assertOk()
            ->assertSee('DistrictGuessr leaderboard')
            ->assertSee('<input type="hidden" name="game" value="districtguessr" />', false)
            ->assertSeeInOrder(['Muthu', '<td class="scores-score">7420</td>', '4 min'], false)
            ->assertDontSee('Ali');

        $this->post('/game-scores/store-winner', ['game' => 'districtguessr', 'run' => $run->Id])
            ->assertRedirect(route('game-scores', ['game' => 'districtguessr']))
            ->assertSessionHas('message', 'Chose Muthu as the winner, with a score of 7420.');
        $winner = GameWinner::sole();
        $this->assertSame(['districtguessr', $run->Id, 'Muthu', $me->Id], [$winner->Game, $winner->GameRunId, $winner->PlayerName, $winner->ChosenById]);

        // A winner in DistrictGuessr's leaderboard and the winners, but not Higher or Lower's game with the same Id.
        $this->get('/game-scores?game=districtguessr')->assertSeeInOrder(['Muthu', 'Winner'], false);
        $this->get('/game-scores')->assertSeeInOrder(['Ali', 'Choose as winner', 'id="winners-heading"', 'Muthu', 'DistrictGuessr', '7420'], false);

        // Only a game of the game chosen.
        $this->post('/game-scores/store-winner', ['game' => 'districtguessr', 'run' => 999])->assertSessionHasErrors('run');
    }

    public function test_the_game_comes_in_bahasa_melayu_under_the_same_name(): void
    {
        $this->districts();

        $this->get('/public/game/districtguessr?lang=ms')->assertOk()
            ->assertSee('<title>DistrictGuessr · '.config('app.name').'</title>', false)
            ->assertSee('data-start="'.route('public.game.districtguessr.start', ['lang' => 'ms']).'"', false)
            ->assertSeeInOrder(['Cara bermain', 'Setiap pusingan menamakan sebuah daerah polis dan negerinya', 'Di manakah', 'Sahkan', 'Daerah seterusnya'])
            ->assertSee('"Next district":"Daerah seterusnya"', false);

        $this->postJson('/public/game/districtguessr/next?lang=ms', ['token' => 'made-up'])
            ->assertJsonPath('message', 'Permainan ini tidak dijumpai. Mulakan yang baharu.');
    }
}
