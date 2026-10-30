<?php

namespace Modules\Game\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Game\Entities\LiveGame;
use Modules\Game\Support\LiveGames;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class LiveGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test's own games, not a file another test left; and with these few figures, any crime type with two
        // different counts can be dealt.
        config(['game.live.store' => 'array', 'game.fewest_counts' => 2]);
        $this->freezeTime();
    }

    /**
     * Break-ins in three police districts: every round is break-ins, two of 60, 100 and 140.
     */
    private function figures(): void
    {
        foreach ([['Batu Pahat', 100], ['Kluang', 140], ['Muar', 60]] as [$district, $crimes]) {
            PoliceDistrict::create(['State' => 'Johor', 'Name' => $district, 'Region' => 'MY-01', 'Latitude' => 1.85, 'Longitude' => 102.93]);
            CrimeStat::create(['State' => 'Johor', 'District' => $district, 'Category' => 'property', 'Type' => 'break_in', 'Year' => 2023, 'Crimes' => $crimes]);
        }
    }

    /**
     * A game hosted by an administrator, from the Game scores page.
     */
    private function hosted(int $rounds = 3): LiveGame
    {
        $this->figures();
        $this->signIn();
        $this->post('/game-scores/store-live', ['rounds' => $rounds])->assertRedirect();

        return LiveGame::query()->latest('Id')->firstOrFail();
    }

    /**
     * Join the game on a phone: the player's Id and token.
     *
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
     * @param  array{player: int, token: string}  $player
     */
    private function answer(LiveGame $game, array $player, string $guess, ?int $round = null): TestResponse
    {
        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => $round ?? $game->fresh()->Round, 'guess' => $guess]);
    }

    private function host(LiveGame $game, string $action = 'state'): TestResponse
    {
        return $action === 'state'
            ? $this->getJson("/game-scores/live-state/{$game->Id}")
            : $this->postJson("/game-scores/live-{$action}/{$game->Id}");
    }

    /**
     * The right guess for the round being played.
     */
    private function right(LiveGame $game): string
    {
        $game->refresh();
        [$shown, $next] = $game->Cards[$game->Round - 1];

        return $next['crimes'] > $shown['crimes'] ? 'higher' : 'lower';
    }

    private function wrong(LiveGame $game): string
    {
        return $this->right($game) === 'higher' ? 'lower' : 'higher';
    }

    public function test_an_administrator_hosts_a_live_game_from_game_scores(): void
    {
        $this->figures();
        $this->signIn();

        $this->get('/game-scores')->assertOk()
            ->assertSee('<h2 id="host-heading">Host a live game</h2>', false)
            ->assertSee('data-confirm="Are you sure you want to host a live game now?" data-confirm-kind="save"', false)
            ->assertSeeInOrder(['<option value="3"', '<option value="10" selected>10</option>', '<option value="30"'], false)
            ->assertSee('None yet. Host one above.');

        $this->post('/game-scores/store-live', ['rounds' => 2])->assertSessionHasErrors('rounds');
        $this->post('/game-scores/store-live', ['rounds' => 31])->assertSessionHasErrors('rounds');

        $response = $this->post('/game-scores/store-live', ['rounds' => 5]);
        $game = LiveGame::sole();
        $response->assertRedirect(route('game-scores/live', ['liveGame' => $game]));

        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $game->Code);
        $this->assertSame([LiveGame::Lobby, 0, 5, 20, 'Signed In'], [$game->Status, $game->Round, $game->Rounds, $game->Seconds, $game->HostName]);
        $this->assertCount(5, $game->Cards);
        foreach ($game->Cards as [$shown, $next]) {
            $this->assertSame($shown['type'], $next['type']);
            $this->assertNotSame($shown['crimes'], $next['crimes']);
        }

        // Its big screen, for the room: the code and where to join, and only the logo along the top.
        $this->get("/game-scores/live/{$game->Id}")->assertOk()
            ->assertSee('<title>Live game '.$game->spacedCode().' · '.config('app.name').'</title>', false)
            ->assertSee('<p class="live-code">'.$game->spacedCode().'</p>', false)
            ->assertSee('Join at <strong>'.preg_replace('#^https?://#', '', route('public.game.live')).'</strong>', false)
            ->assertSee('data-state="'.route('game-scores/live-state', ['liveGame' => $game]).'"', false)
            ->assertSee(versioned_asset('modules/game/live-host.js'), false)
            ->assertDontSee('aria-label="Pages"', false);

        // Listed on Game scores, with its big screen.
        $this->get('/game-scores')->assertSeeInOrder(['Live games', $game->spacedCode(), 'by Signed In', '5, not started', 'Big screen'], false);
    }

    public function test_without_figures_there_is_no_live_game(): void
    {
        $this->signIn();

        $this->post('/game-scores/store-live', ['rounds' => 5])->assertRedirect('/game-scores')
            ->assertSessionHas('status', 'error');
        $this->assertDatabaseCount('LiveGames', 0);
    }

    public function test_only_administrators_host(): void
    {
        $game = $this->hosted();
        auth()->logout();

        $this->get("/game-scores/live/{$game->Id}")->assertRedirect('/login');

        $this->actingAs(User::create(['Name' => 'Not Admin', 'Email' => 'player@example.com']));
        $this->post('/game-scores/store-live', ['rounds' => 5])->assertForbidden();
        $this->get("/game-scores/live/{$game->Id}")->assertForbidden();
        foreach (['state', 'next', 'close', 'finish'] as $action) {
            $this->host($game, $action)->assertForbidden();
        }

        $this->assertSame([LiveGame::Lobby, 1], [$game->fresh()->Status, LiveGame::count()]);
    }

    public function test_players_join_with_the_code_and_a_name_no_one_else_in_the_game_has(): void
    {
        $game = $this->hosted();

        $this->get('/public/game/live?code='.$game->Code)->assertOk()
            ->assertSee('value="'.$game->Code.'"', false)
            ->assertSee('data-api="'.url('/api/game/live').'" data-language=""', false)
            ->assertSee('<a href="'.route('public.game').'" class="public-link active" aria-current="page">', false);
        $this->get('/public/game/live?code=12')->assertOk()->assertSee('name="code" class="live-code-field" value=""', false);

        $joined = $this->postJson('/api/game/live/join', ['code' => $game->Code, 'name' => '  Siti   Aminah '])->assertOk()->json();
        $this->assertSame(['game' => $game->Id, 'name' => 'Siti Aminah'], array_intersect_key($joined, ['game' => 0, 'name' => 0]));
        $this->assertSame(48, strlen($joined['token']));

        $this->postJson('/api/game/live/join', ['code' => $game->Code, 'name' => 'siti aminah'])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Someone in this game already has that name. Add an initial, or choose another.');
        $this->postJson('/api/game/live/join', ['code' => '12345', 'name' => 'Ali'])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'The code is the six numbers on the big screen.');
        $this->postJson('/api/game/live/join', ['code' => $game->Code === '999999' ? '999998' : '999999', 'name' => 'Ali'])->assertNotFound()
            ->assertJsonPath('message', 'No game has that code. Check it on the big screen.');
        $this->postJson('/api/game/live/join', ['code' => $game->Code, 'name' => '<b>'])->assertUnprocessable();

        // In Bahasa Melayu, as the page asks.
        $this->postJson('/api/game/live/join?lang=ms', ['code' => '', 'name' => 'Ali'])->assertJsonPath('errors.code.0', 'Taip kod pada skrin besar.');

        // Not after the game has finished, or once it's old.
        $this->travel(13)->hours();
        $this->postJson('/api/game/live/join', ['code' => $game->Code, 'name' => 'Ali'])->assertNotFound();
        $this->assertSame(1, $game->players()->count());
    }

    public function test_the_lobby_shows_who_has_joined(): void
    {
        $game = $this->hosted();
        $siti = $this->join($game, 'Siti');
        $this->join($game, 'Ali');

        $this->host($game)->assertOk()->assertJson(['phase' => 'lobby', 'round' => 0, 'rounds' => 3, 'players' => 2, 'names' => ['Siti', 'Ali']]);
        $this->check($game, $siti)->assertOk()
            ->assertJson(['phase' => 'lobby', 'name' => 'Siti', 'players' => 2, 'score' => 0, 'rank' => 1])
            ->assertJsonMissingPath('cards');

        // Only as a player in the game.
        $this->check($game, ['player' => $siti['player'], 'token' => 'made-up'])->assertForbidden()
            ->assertJsonPath('message', "You're not in this game. Join it with its code.");
        $this->withHeaders(['X-Player' => $siti['player'], 'X-Player-Token' => $siti['token']])->getJson('/api/game/live/999')->assertNotFound();
    }

    public function test_a_round_gets_ready_then_takes_answers_and_shows_its_answer_once_everyone_has_answered(): void
    {
        $game = $this->hosted();
        $siti = $this->join($game, 'Siti');
        $ali = $this->join($game, 'Ali');
        $now = app(LiveGames::class)->now();

        // Three seconds to get ready, then twenty to answer. Everyone sees the cards, but not the count to guess.
        $this->host($game, 'next')->assertOk()->assertJson(['phase' => 'ready', 'round' => 1, 'opensAt' => $now + 3000, 'closesAt' => $now + 23000]);
        $ready = $this->check($game, $siti)->assertJson(['phase' => 'ready', 'round' => 1])->json();
        $this->assertArrayHasKey('crimes', $ready['cards'][0]);
        $this->assertArrayNotHasKey('crimes', $ready['cards'][1]);
        $this->assertArrayNotHasKey('answer', $ready);
        $this->answer($game, $siti, $this->right($game))->assertStatus(409)
            ->assertJsonPath('message', "This round isn't taking answers now.");

        // Pressing Next again mid-round does nothing.
        $this->host($game, 'next')->assertJson(['phase' => 'ready', 'round' => 1]);

        $this->travel(3)->seconds();
        // While answering, no one's points or place, which would give away who was right.
        $this->check($game, $siti)->assertJson(['phase' => 'question', 'guess' => null])->assertJsonMissingPath('score')->assertJsonMissingPath('rank');

        // Right straight away: all the points.
        $this->answer($game, $siti, $this->right($game))->assertOk()->assertExactJson(['received' => true]);
        $this->answer($game, $siti, $this->wrong($game))->assertStatus(409)->assertJsonPath('message', "You've answered this round already.");
        $this->check($game, $siti)->assertJson(['phase' => 'question', 'guess' => $this->right($game)]);
        $this->host($game)->assertJson(['phase' => 'question', 'answered' => 1, 'players' => 2]);

        // Wrong: none. Then everyone has answered, so the answer shows.
        $this->travel(5)->seconds();
        $this->answer($game, $ali, $this->wrong($game))->assertOk();

        [, $next] = $game->fresh()->Cards[0];
        $this->check($game, $siti)->assertJson([
            'phase' => 'reveal', 'answer' => $next['crimes'], 'result' => ['correct' => true, 'points' => 1000], 'score' => 1000, 'rank' => 1,
        ]);
        $this->check($game, $ali)->assertJson(['phase' => 'reveal', 'result' => ['correct' => false, 'points' => 0], 'score' => 0, 'rank' => 2]);
        $this->host($game)->assertJson([
            'phase' => 'reveal', 'answer' => $next['crimes'], 'right' => $this->right($game), 'last' => false,
            'said' => [$this->right($game) => 1, $this->wrong($game) => 1],
            'leaderboard' => [['rank' => 1, 'name' => 'Siti', 'score' => 1000], ['rank' => 2, 'name' => 'Ali', 'score' => 0]],
        ]);

        // The next round.
        $this->host($game, 'next')->assertJson(['phase' => 'ready', 'round' => 2]);
        $this->check($game, $siti)->assertJson(['phase' => 'ready', 'round' => 2, 'guess' => null, 'score' => 1000]);
    }

    public function test_a_right_answer_scores_fewer_points_the_longer_it_takes(): void
    {
        $game = $this->hosted(rounds: 3);
        $players = collect(['Siti', 'Ali', 'Chong', 'Devi'])->mapWithKeys(fn (string $name) => [$name => $this->join($game, $name)]);
        $this->host($game, 'next');
        $this->travel(3)->seconds();

        // Half a second before the cards show counts as straight away, for a phone whose clock is a little ahead.
        $this->travel(-500)->milliseconds();
        $this->answer($game, $players['Siti'], $this->right($game))->assertOk();
        $this->travel(500)->milliseconds();

        // Halfway: three quarters of the points. At the last moment: half. Over a second after it, which is allowed
        // for an answer to arrive, too late.
        $this->travel(10)->seconds();
        $this->answer($game, $players['Ali'], $this->right($game))->assertOk();
        $this->travel(10)->seconds();
        $this->answer($game, $players['Chong'], $this->right($game))->assertOk();
        $this->travel(1001)->milliseconds();
        $this->answer($game, $players['Devi'], $this->right($game))->assertStatus(409);

        $this->assertSame(
            ['Siti' => 1000, 'Ali' => 750, 'Chong' => 500, 'Devi' => 0],
            $game->players()->orderBy('Id')->pluck('Score', 'PlayerName')->all(),
        );
        $this->check($game, $players['Devi'])->assertJson(['phase' => 'reveal', 'result' => null, 'score' => 0, 'rank' => 4]);

        // An answer to another round than the one being played isn't taken either.
        $this->host($game, 'next');
        $this->travel(3)->seconds();
        $this->answer($game, $players['Siti'], 'higher', round: 1)->assertStatus(409);
    }

    public function test_the_host_shows_the_answer_before_the_time_is_up(): void
    {
        $game = $this->hosted();
        $siti = $this->join($game, 'Siti');
        $this->join($game, 'Ali');
        $this->host($game, 'next');
        $this->travel(4)->seconds();
        $this->answer($game, $siti, $this->right($game));

        $this->host($game, 'close')->assertJson(['phase' => 'reveal', 'said' => [$this->right($game) => 1, $this->wrong($game) => 0]]);
        $this->answer($game, $this->join($game, 'Chong'), $this->right($game))->assertStatus(409);
        $this->check($game, $siti)->assertJson(['phase' => 'reveal', 'result' => ['correct' => true]]);

        // Closing again does nothing.
        $this->host($game, 'close')->assertJson(['phase' => 'reveal', 'round' => 1]);
    }

    public function test_after_the_last_round_comes_the_leaderboard(): void
    {
        $game = $this->hosted(rounds: 3);
        $players = collect(['Siti', 'Ali', 'Chong'])->mapWithKeys(fn (string $name) => [$name => $this->join($game, $name)]);

        foreach ([1, 2, 3] as $round) {
            $this->host($game, 'next');
            $this->travel(3)->seconds();
            // Siti right at once; Ali and Chong right a little later, together, so level.
            $this->answer($game, $players['Siti'], $this->right($game));
            $this->travel(4)->seconds();
            $this->answer($game, $players['Ali'], $this->right($game));
            $this->answer($game, $players['Chong'], $this->right($game));
        }

        // Four seconds in: nine tenths of the points.
        $this->host($game)->assertJson(['phase' => 'reveal', 'round' => 3, 'last' => true]);
        $this->host($game, 'next')->assertJson([
            'phase' => 'finished',
            'leaderboard' => [
                ['rank' => 1, 'name' => 'Siti', 'score' => 3000],
                ['rank' => 2, 'name' => 'Ali', 'score' => 2700],
                ['rank' => 2, 'name' => 'Chong', 'score' => 2700],
            ],
        ]);
        $this->assertSame(LiveGame::Finished, $game->fresh()->Status);
        $this->assertNotNull($game->fresh()->FinishedAt);

        $this->check($game, $players['Chong'])->assertJson([
            'phase' => 'finished', 'score' => 2700, 'rank' => 2, 'players' => 3,
            'leaderboard' => [
                ['rank' => 1, 'name' => 'Siti', 'you' => false],
                ['rank' => 2, 'name' => 'Ali', 'you' => false],
                ['rank' => 2, 'name' => 'Chong', 'you' => true],
            ],
        ]);

        // Game scores lists it with its winner and its results.
        $this->get('/game-scores')->assertSeeInOrder(['Live games', $game->spacedCode(), 'Siti', '3,000 points', 'Results'], false);

        // No more joining, or moving it on.
        $this->postJson('/api/game/live/join', ['code' => $game->Code, 'name' => 'Devi'])->assertNotFound();
        $this->host($game, 'next')->assertJson(['phase' => 'finished']);
    }

    public function test_the_host_can_end_the_game_early(): void
    {
        $game = $this->hosted(rounds: 10);
        $siti = $this->join($game, 'Siti');
        $this->host($game, 'next');
        $this->travel(3)->seconds();
        $this->answer($game, $siti, $this->right($game));

        $this->host($game, 'finish')->assertJson(['phase' => 'finished', 'round' => 1, 'leaderboard' => [['name' => 'Siti', 'score' => 1000]]]);
        $this->check($game, $siti)->assertJson(['phase' => 'finished', 'rank' => 1]);
    }

    public function test_the_cards_come_in_the_players_language(): void
    {
        $game = $this->hosted();
        $siti = $this->join($game, 'Siti');
        $this->host($game, 'next');

        $this->assertSame('Pecah rumah', $this->check($game, $siti, '?lang=ms')->json('cards.0.type'));
        $this->assertSame('Break-in', $this->check($game, $siti)->json('cards.0.type'));
        $this->assertSame('Break-in', $this->host($game)->json('cards.1.type'));
        $this->check($game, ['player' => 0, 'token' => ''], '?lang=ms')->assertJsonPath('message', 'Anda bukan pemain dalam permainan ini. Sertai dengan kodnya.');

        $this->get('/public/game/live?lang=ms')->assertOk()->assertSee('data-language="?lang=ms"', false)->assertSee('Kod permainan');
    }

    /**
     * A game of DistrictGuessr hosted by an administrator, with three police districts to find: Batu Pahat, Kuantan and
     * Kota Bharu.
     */
    private function hostedGuessr(int $rounds = 3, ?string $difficulty = null): LiveGame
    {
        foreach ([['Johor', 'Batu Pahat', 'MY-01', 1.85, 102.93], ['Pahang', 'Kuantan', 'MY-06', 3.81, 103.33], ['Kelantan', 'Kota Bharu', 'MY-03', 6.13, 102.24]] as [$state, $name, $region, $lat, $lng]) {
            PoliceDistrict::create(['State' => $state, 'Name' => $name, 'Region' => $region, 'Latitude' => $lat, 'Longitude' => $lng]);
        }
        $this->signIn();
        $this->post('/game-scores/store-live', array_filter(['game' => 'districtguessr', 'difficulty' => $difficulty, 'rounds' => $rounds]))->assertRedirect();

        return LiveGame::query()->latest('Id')->firstOrFail();
    }

    /**
     * A pin on the map for the round being played, a degree north of where the district is for each $off.
     *
     * @param  array{player: int, token: string}  $player
     */
    private function pin(LiveGame $game, array $player, int $off = 0): TestResponse
    {
        $game->refresh();
        $district = $game->Cards[$game->Round - 1];

        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => $game->Round, 'lat' => $district['lat'] + $off, 'lng' => $district['lng']]);
    }

    public function test_an_administrator_hosts_a_live_game_of_districtguessr(): void
    {
        $game = $this->hostedGuessr();

        // Hard when no level is given.
        $this->assertSame(['districtguessr', 'hard', LiveGame::Lobby, 3, 40], [$game->Game, $game->Difficulty, $game->Status, $game->Rounds, $game->Seconds]);
        $this->assertCount(3, collect($game->Cards)->pluck('name')->unique());
        $this->assertSame(['name', 'state', 'place', 'region', 'lat', 'lng'], array_keys($game->Cards[0]));

        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['<select id="live-game" name="game">', '<option value="higher-or-lower"', 'Higher or Lower', '<option value="districtguessr"', 'DistrictGuessr'], false)
            ->assertSeeInOrder(['<select id="live-difficulty" name="difficulty">', '<option value="easy"', 'Easy', '<option value="hard"', 'Hard'], false)
            ->assertSeeInOrder(['Live games', $game->spacedCode(), 'DistrictGuessr (Hard)', '3, not started'], false);

        // Its big screen has the map, and the game's title.
        $this->get("/game-scores/live/{$game->Id}")->assertOk()
            ->assertSee('alt="DistrictGuessr" lang="en"', false)
            ->assertSee('data-boundaries="'.versioned_asset(config('map.boundaries')).'"', false)
            ->assertSee(versioned_asset('modules/game/guessr-map.js'), false)
            ->assertSeeInOrder(['3 rounds of', '40 seconds each, and', 'the closer the pin, and the sooner, the more points.'])
            ->assertDontSee('data-game-card', false);

        // Only the games there are, and only with enough districts for the rounds.
        $this->post('/game-scores/store-live', ['game' => 'made-up', 'rounds' => 3])->assertSessionHasErrors('game');
        $this->post('/game-scores/store-live', ['game' => 'districtguessr', 'rounds' => 4])->assertRedirect('/game-scores')
            ->assertSessionHas('status', 'error');
        $this->assertSame(1, LiveGame::count());
    }

    public function test_the_host_plays_districtguessr_on_easy_for_everyone(): void
    {
        $game = $this->hostedGuessr(difficulty: 'easy');
        $this->assertSame('easy', $game->Difficulty);
        $siti = $this->join($game, 'Siti');

        // The big screen and every phone know the level, and each district's region, to zoom into its state.
        $this->get("/game-scores/live/{$game->Id}")->assertSee('On Easy: each map zooms into the district&#039;s state.', false);
        $this->check($game, $siti)->assertJson(['phase' => 'lobby', 'difficulty' => 'easy']);
        $this->host($game, 'next');
        $district = $game->fresh()->Cards[0];
        $this->check($game, $siti)->assertJson(['difficulty' => 'easy', 'district' => ['name' => $district['name'], 'region' => $district['region']]]);
        $this->host($game)->assertJson(['difficulty' => 'easy', 'district' => ['region' => $district['region']]]);
        $this->get('/game-scores')->assertSeeInOrder(['Live games', $game->spacedCode(), 'DistrictGuessr (Easy)'], false);

        // Only Easy or Hard; and a level means nothing to Higher or Lower.
        $this->post('/game-scores/store-live', ['game' => 'districtguessr', 'difficulty' => 'medium', 'rounds' => 3])->assertSessionHasErrors('difficulty');
        $this->post('/game-scores/store-live', ['game' => 'higher-or-lower', 'difficulty' => 'easy', 'rounds' => 3]);
        $this->assertSame(1, LiveGame::count());
    }

    public function test_districtguessr_rounds_take_pins_and_score_their_distance_and_speed(): void
    {
        $game = $this->hostedGuessr();
        $players = collect(['Siti', 'Ali', 'Chong', 'Devi'])->mapWithKeys(fn (string $name) => [$name => $this->join($game, $name)]);

        // Everyone sees the district and its state, but not where it is until the answer.
        $this->host($game, 'next')->assertJson(['game' => 'districtguessr', 'phase' => 'ready', 'round' => 1, 'seconds' => 40]);
        $district = $game->fresh()->Cards[0];
        $this->check($game, $players['Siti'])
            ->assertJson(['game' => 'districtguessr', 'phase' => 'ready', 'district' => ['name' => $district['name'], 'place' => $district['place']], 'pin' => null])
            ->assertJsonMissingPath('district.lat')->assertJsonMissingPath('answer')->assertJsonMissingPath('cards');
        $this->pin($game, $players['Siti'])->assertStatus(409);

        $this->travel(3)->seconds();
        $this->host($game)->assertJson(['phase' => 'question', 'district' => ['name' => $district['name']]])->assertJsonMissingPath('answer');

        // Right on it, straight away: all the points.
        $this->pin($game, $players['Siti'])->assertOk()->assertExactJson(['received' => true]);
        $this->check($game, $players['Siti'])->assertJson(['phase' => 'question', 'guess' => 'pin', 'pin' => ['lat' => $district['lat'], 'lng' => $district['lng']]]);

        // A pin's a latitude and longitude, not a guess.
        $this->answer($game, $players['Ali'], 'higher')->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);

        // A degree off (111.2 km: 336 points) halfway through the time: an eighth fewer. Right on it at the last moment:
        // a quarter fewer. After it, too late.
        $this->travel(20)->seconds();
        $this->pin($game, $players['Ali'], off: 1)->assertOk();
        $this->travel(20)->seconds();
        $this->pin($game, $players['Chong'])->assertOk();
        $this->travel(1001)->milliseconds();
        $this->pin($game, $players['Devi'])->assertStatus(409);

        $this->assertSame(
            ['Siti' => 1000, 'Ali' => 294, 'Chong' => 750, 'Devi' => 0],
            $game->players()->orderBy('Id')->pluck('Score', 'PlayerName')->all(),
        );

        $answer = ['lat' => $district['lat'], 'lng' => $district['lng']];
        $this->check($game, $players['Ali'])->assertJson([
            'phase' => 'reveal', 'answer' => $answer, 'result' => ['correct' => true, 'points' => 294, 'km' => 111.2], 'score' => 294, 'rank' => 3,
        ]);
        $this->check($game, $players['Devi'])->assertJson(['phase' => 'reveal', 'answer' => $answer, 'result' => null, 'pin' => null, 'rank' => 4]);

        // The big screen: where it is, and everyone's pins, closest first.
        $reveal = $this->host($game)->assertJson([
            'phase' => 'reveal', 'answer' => $answer, 'last' => false,
            'leaderboard' => [['name' => 'Siti', 'score' => 1000], ['name' => 'Chong', 'score' => 750], ['name' => 'Ali', 'score' => 294]],
        ])->assertJsonMissingPath('said')->json();
        $this->assertSame([['Chong', 0.0, 750], ['Siti', 0.0, 1000], ['Ali', 111.2, 294]], array_map(fn (array $pin) => [$pin['name'], (float) $pin['km'], $pin['points']], $reveal['pins']));
        $this->assertEqualsWithDelta($district['lat'] + 1, $reveal['pins'][2]['lat'], 0.00001);

        // The next round's district.
        $this->host($game, 'next')->assertJson(['phase' => 'ready', 'round' => 2, 'district' => ['name' => $game->fresh()->Cards[1]['name']]]);
    }

    public function test_the_phone_page_is_ready_for_either_game(): void
    {
        $this->get('/public/game/live')->assertOk()
            ->assertSee('<title>Live game · '.config('app.name').'</title>', false)
            ->assertSee('<h1 data-live-game="none">Join a live game</h1>', false)
            ->assertSee('<div data-live-game="higher-or-lower" hidden>', false)
            ->assertSee('<div data-live-game="districtguessr" hidden>', false)
            ->assertSee('data-boundaries="'.versioned_asset(config('map.boundaries')).'"', false)
            ->assertSee('The sooner you confirm, the more of them you keep: 75% at the last moment.');

        $this->get('/public/game/live?lang=ms')->assertOk()
            ->assertSee('<title>Permainan langsung · '.config('app.name').'</title>', false)
            ->assertSee('75% pada saat terakhir');
    }

    public function test_a_phones_check_reads_what_is_kept_rather_than_the_database(): void
    {
        $game = $this->hosted();
        $siti = $this->join($game, 'Siti');
        $this->host($game, 'next');
        $this->check($game, $siti)->assertOk();

        DB::enableQueryLog();
        $this->check($game, $siti)->assertOk()->assertJson(['phase' => 'ready']);
        $this->travel(3)->seconds();
        // Kept for two seconds: read again once.
        $this->check($game, $siti)->assertOk()->assertJson(['phase' => 'question']);
        $this->check($game, $siti)->assertOk();

        $this->assertCount(3, collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_contains($query, 'Live')));
    }
}
