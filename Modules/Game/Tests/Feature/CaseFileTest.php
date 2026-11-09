<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Game\Entities\CaseRun;
use Modules\Game\Entities\GameWinner;
use Modules\Game\Entities\LiveGame;
use Modules\Game\Support\CaseFile;
use Modules\Game\Support\DistrictGuessr;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class CaseFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test's own live games, not a file another test left.
        config(['game.live.store' => 'array']);
    }

    /**
     * Six police districts in Johor and six in Pahang, with 2022's and 2023's figures. District n had 120n crimes in
     * 2022 and 130n in 2023 (up 8%): its break-ins, 60n, the most of the types the games use, though rape, left out,
     * was more.
     *
     * @param  array<string, array{string, float, float}>  $states
     */
    private function figures(array $states = ['Johor' => ['MY-01', 1.5, 103.0], 'Pahang' => ['MY-06', 3.5, 102.5]], int $districts = 6): void
    {
        foreach ($states as $state => [$region, $lat, $lng]) {
            foreach (range(1, $districts) as $n) {
                $name = "{$state} {$n}";
                PoliceDistrict::create(['State' => $state, 'Name' => $name, 'Region' => $region, 'Latitude' => $lat + $n / 10, 'Longitude' => $lng + $n / 10]);

                foreach ([2022, 2023] as $year) {
                    $more = ($year - 2022) * 10 * $n;
                    foreach ([
                        ['property', 'all', 100 * $n + $more], ['assault', 'all', 20 * $n],
                        ['property', 'break_in', 60 * $n], ['property', 'theft_other', 40 * $n + $more],
                        ['assault', 'causing_injury', 20 * $n], ['assault', 'rape', 999],
                    ] as [$category, $type, $crimes]) {
                        CrimeStat::create(['State' => $state, 'District' => $name, 'Category' => $category, 'Type' => $type, 'Year' => $year, 'Crimes' => $crimes]);
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
        return $this->postJson('/public/game/case-file/start', ['name' => 'Siti'])->assertOk()->json('token');
    }

    /**
     * The case being played, from the game kept on the server.
     *
     * @return array<string, mixed>
     */
    private function playing(): array
    {
        $run = CaseRun::query()->latest('Id')->firstOrFail();

        return $run->Dealt[$run->round() - 1];
    }

    private function accuse(string $token, ?int $suspect): TestResponse
    {
        return $this->postJson('/public/game/case-file/accuse', ['token' => $token, 'suspect' => $suspect]);
    }

    private function clue(string $token, string $clue): TestResponse
    {
        return $this->postJson('/public/game/case-file/clue', ['token' => $token, 'clue' => $clue]);
    }

    public function test_the_game_has_its_page_and_its_tile_on_the_games_page(): void
    {
        $this->figures();

        $this->get('/public/game/case-file')->assertOk()
            ->assertSee('<title>Case File · '.config('app.name').'</title>', false)
            ->assertSee('<h1 class="visually-hidden">Case File</h1>', false)
            ->assertSee(versioned_asset('modules/game/title-case-file.svg'), false)
            ->assertSee('data-start="'.route('public.game.case-file.start').'"', false)
            ->assertSee('data-seconds="60" data-cases="5"', false)
            // All of it in one pixel screen: the cover with how to play and the name, the office with its clues and the
            // corkboard, the suspects, the closing report, and the text box.
            ->assertSee('data-case-screen data-mode="solo" data-case-costs="{&quot;rap&quot;:0,&quot;trend&quot;:150,&quot;rank&quot;:150,&quot;photo&quot;:250,&quot;tip&quot;:300}"', false)
            ->assertSeeInOrder([
                'data-cs-view="room"', versioned_asset('modules/game/case-room.png'), 'data-cs="to-board"',
                'data-case-clue="rap"', 'data-case-clue="trend"', 'data-case-clue="rank"', 'data-case-clue="tip"', 'data-case-clue="photo"',
                'data-cs-view="clue"', 'data-cs-view="board"', 'Suspects', 'Pick a suspect',
                'data-cs-view="cover"', 'How to play', 'Tap the evidence on the desk for more clues. Each one you open takes some off the case: from 1,000 down to 150.',
                'Tap the corkboard to see the six suspects, pick one and accuse them. 60 seconds a case, 5 cases.', 'name="name"', 'Open the file',
                'data-cs-view="report"', 'Case report', 'data-cs="dialogue"',
            ], false)
            ->assertSeeInOrder([versioned_asset('modules/game/case-screen.js'), versioned_asset('modules/game/case-file.js')], false);

        // On the Games page, after Skyline.
        $this->get('/public/game')->assertSeeInOrder([
            'id="tile-skyline"', 'href="'.route('public.game.case-file').'" id="tile-case-file"',
            versioned_asset('modules/game/tile-case-file.png'), '<span class="library-tile-name">Case File</span>', 'id="tile-live"',
            'id="game-case-file"', 'alt="Case File"', versioned_asset('modules/game/picture-case-file.png'),
            '5 cases', 'Each a police district, with its rap sheet open and 60 seconds to crack it.', 'Fewer clues, more points', 'Six suspects',
        ], false);
    }

    public function test_without_enough_districts_there_is_no_game(): void
    {
        // Five districts, one short of the six suspects.
        $this->figures(['Johor' => ['MY-01', 1.5, 103.0]], districts: 5);

        $this->get('/public/game/case-file')->assertOk()->assertSee('There are no crime figures to play with yet.')->assertDontSee('data-case-file ', false);
        $this->postJson('/public/game/case-file/start', ['name' => 'Siti'])->assertStatus(503);
        $this->assertDatabaseCount('CaseRuns', 0);
    }

    public function test_a_game_is_five_cases_and_which_suspect_it_is_stays_on_the_server(): void
    {
        $this->figures();
        $this->freezeSecond();

        $started = $this->postJson('/public/game/case-file/start', ['name' => '  Siti  Aminah '])->assertOk()
            ->assertJson(['cases' => 5, 'seconds' => 60, 'ready' => 3, 'asked' => ['case' => 1, 'worth' => 1000]])
            ->assertJsonMissingPath('asked.culprit')
            ->json();

        $run = CaseRun::sole();
        $this->assertSame([$run->Token, 'Siti Aminah', 'siti aminah', 0, [], ['rap']], [$started['token'], $run->PlayerName, $run->PlayerKey, $run->Score, $run->Picks, $run->Opened]);
        $this->assertTrue($run->AskedAt->equalTo(now()->addSeconds(3)));

        // Five different districts, each among its six suspects, all different.
        $this->assertCount(5, array_unique(array_column($run->Dealt, 'name')));
        foreach ($run->Dealt as $case) {
            $this->assertCount(6, array_unique(array_column($case['suspects'], 'name')));
            $this->assertSame($case['name'], $case['suspects'][$case['culprit']]['name']);
        }

        // The page has the suspects, their states, and only the rap sheet.
        $case = $run->Dealt[0];
        $this->assertSame($case['suspects'], $started['asked']['suspects']);
        $this->assertSame(['rap'], array_keys($started['asked']['clues']));
        $this->assertStringNotContainsString('"tip"', json_encode($started['asked']));

        $this->travel(3)->seconds();
        $this->accuse($started['token'], 0)->assertOk();
        $this->postJson('/public/game/case-file/next', ['token' => $started['token']])->assertOk()
            ->assertJson(['asked' => ['case' => 2, 'suspects' => $run->Dealt[1]['suspects'], 'worth' => 1000]]);
    }

    public function test_the_clues_say_what_the_figures_do(): void
    {
        $this->figures();
        $token = $this->start();
        $case = $this->playing();
        $n = (int) substr($case['name'], -1);
        $state = strtok($case['name'], ' ');

        // Its crimes in the latest year and its most common, of the types the games use (not rape, left out).
        $this->assertSame(['year' => 2023, 'crimes' => 130 * $n, 'count' => 60 * $n, 'type' => 'Break-in', 'icon' => config('game.icons.break_in')],
            CaseFile::clue($case, 'rap'));

        // Up 8% on the year before, the first year too; 6th of 6 for the smallest, 1st for the largest; the photo in
        // its state, round about its pin; its first letter.
        $this->clue($token, 'trend')->assertOk()->assertJson(['clue' => 'trend', 'said' => ['from' => 2022, 'to' => 2023, 'change' => 8, 'since' => 2022, 'long' => 8], 'worth' => 850]);
        $this->clue($token, 'rank')->assertOk()->assertJson(['said' => ['rank' => 7 - $n, 'of' => 6, 'year' => 2023], 'worth' => 700]);
        $photo = $this->clue($token, 'photo')->assertOk()->assertJson(['worth' => 450])->json('said');
        $this->assertSame([$state === 'Johor' ? 'MY-01' : 'MY-06'], $photo['regions']);
        $pin = PoliceDistrict::query()->where('Name', $case['name'])->sole();
        $this->assertLessThanOrEqual($photo['km'] / 2 + 0.1, DistrictGuessr::km($pin->Latitude, $pin->Longitude, $photo['lat'], $photo['lng']));
        $this->clue($token, 'tip')->assertOk()->assertJson(['said' => ['letter' => $state[0]], 'worth' => 150]);

        // Opening one again takes nothing more; and only the clues there are.
        $this->clue($token, 'tip')->assertOk()->assertJsonPath('worth', 150);
        $this->clue($token, 'fingerprints')->assertUnprocessable();
        $this->assertSame(['rap', 'trend', 'rank', 'photo', 'tip'], CaseRun::sole()->Opened);
    }

    public function test_a_case_scores_what_it_was_worth_when_solved(): void
    {
        $this->figures();
        $token = $this->start();

        // On the rap sheet alone: all of it.
        $culprit = $this->playing()['culprit'];
        $this->accuse($token, $culprit)->assertOk()
            ->assertJson(['case' => 1, 'late' => false, 'suspect' => $culprit, 'culprit' => $culprit, 'right' => true, 'points' => 1000, 'score' => 1000, 'over' => false])
            ->assertJsonStructure(['clues' => ['rap', 'trend', 'rank', 'photo', 'tip']]);

        // With the photo open: 750. A wrong suspect: none, and who it was.
        $this->postJson('/public/game/case-file/next', ['token' => $token]);
        $this->clue($token, 'photo');
        $this->accuse($token, $this->playing()['culprit'])->assertOk()->assertJson(['points' => 750, 'score' => 1750]);
        $this->postJson('/public/game/case-file/next', ['token' => $token]);
        $culprit = $this->playing()['culprit'];
        $this->accuse($token, ($culprit + 1) % 6)->assertOk()->assertJson(['right' => false, 'culprit' => $culprit, 'points' => 0, 'score' => 1750]);

        $run = CaseRun::sole();
        $this->assertSame([[true, 1, 1000], [true, 2, 750], [false, 1, 0]], array_map(fn (array $pick) => [$pick['right'], $pick['clues'], $pick['points']], $run->Picks));
        $this->assertNotNull($run->ScoredAt);
    }

    public function test_the_points_come_off_by_the_clues_opened(): void
    {
        $this->assertSame(1000, CaseFile::worth(['rap']));
        $this->assertSame(850, CaseFile::worth(['rap', 'trend']));
        $this->assertSame(700, CaseFile::worth(['rap', 'tip']));
        $this->assertSame(150, CaseFile::worth(CaseFile::order()));
        // A live game opens them in order: the rap sheet, the trend report, the ranking, the photo, the tip-off.
        $this->assertSame([1000, 1000, 850, 700, 450, 150], [CaseFile::worthAt(0), CaseFile::worthAt(1), CaseFile::worthAt(2), CaseFile::worthAt(3), CaseFile::worthAt(4), CaseFile::worthAt(5)]);
    }

    public function test_no_pick_or_a_late_one_scores_nothing(): void
    {
        $this->figures();
        $this->freezeSecond();
        $token = $this->start();

        // The time ran out without an accusation.
        $this->accuse($token, null)->assertOk()->assertJson(['late' => true, 'suspect' => null, 'right' => false, 'points' => 0]);

        // The right one, but after the 60 seconds and the 4 more allowed for it to arrive.
        $this->postJson('/public/game/case-file/next', ['token' => $token]);
        $this->travel(65)->seconds();
        $this->accuse($token, $this->playing()['culprit'])->assertOk()->assertJson(['late' => true, 'points' => 0]);

        // Within them, it counts.
        $this->postJson('/public/game/case-file/next', ['token' => $token]);
        $this->travel(63)->seconds();
        $this->accuse($token, $this->playing()['culprit'])->assertOk()->assertJson(['late' => false, 'points' => 1000]);

        // Once a case is closed, no more picks or clues; and a pick is one of the six.
        $this->accuse($token, 0)->assertStatus(409)->assertJsonPath('message', 'This case is closed. Go on to the next one.');
        $this->clue($token, 'trend')->assertStatus(409);
        $this->postJson('/public/game/case-file/next', ['token' => $token]);
        $this->accuse($token, 6)->assertUnprocessable();
        $this->accuse($token, -1)->assertUnprocessable();
    }

    public function test_the_cases_go_in_order_and_the_game_ends_after_five(): void
    {
        $this->figures();
        $token = $this->start();

        $this->postJson('/public/game/case-file/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This case is still waiting for its pick.');

        foreach (range(1, 5) as $case) {
            $last = $this->accuse($token, $this->playing()['culprit'])->assertOk()->assertJsonPath('case', $case);

            if ($case < 5) {
                $last->assertJsonPath('over', false)->assertJsonPath('summary', null);
                $this->postJson('/public/game/case-file/next', ['token' => $token])->assertOk();
            }
        }

        $last->assertJson(['over' => true, 'score' => 5000]);
        $this->assertCount(5, $last->json('summary'));
        $this->assertSame(['district', 'state', 'right', 'clues', 'points'], array_keys($last->json('summary.0')));
        $this->assertNotNull(CaseRun::sole()->EndedAt);

        $this->postJson('/public/game/case-file/next', ['token' => $token])->assertStatus(409)
            ->assertJsonPath('message', 'This game has ended. Start a new one.');
        $this->postJson('/public/game/case-file/next', ['token' => 'made-up'])->assertNotFound();
    }

    public function test_game_scores_shows_case_file_and_its_winner(): void
    {
        $this->figures();
        $me = $this->signIn();
        $started = now()->subHour();
        $run = CaseRun::create([
            'Token' => 'a-token', 'PlayerName' => 'Muthu', 'PlayerKey' => 'muthu', 'Score' => 3550, 'Dealt' => [], 'Opened' => [], 'Picks' => [],
            'ScoredAt' => $started->copy()->addMinutes(3), 'StartedAt' => $started, 'EndedAt' => $started->copy()->addMinutes(3),
        ]);

        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['aria-label="Games"', 'Higher or Lower', 'DistrictGuessr Easy', 'DistrictGuessr Hard', 'Skyline', 'Case File'], false)
            ->assertDontSee('Muthu');

        $this->get('/game-scores?game=case-file')->assertOk()
            ->assertSee('Case File leaderboard')
            ->assertSeeInOrder(['Muthu', '<td class="scores-score">3550</td>', '3 min'], false)
            ->assertSee('<option value="case-file" selected>Case File</option>', false);

        $this->post('/game-scores/store-winner', ['game' => 'case-file', 'run' => $run->Id])
            ->assertRedirect(route('game-scores', ['game' => 'case-file']))
            ->assertSessionHas('message', 'Chose Muthu as the winner, with a score of 3550.');
        $this->assertSame(['case-file', $run->Id, $me->Id], [GameWinner::sole()->Game, GameWinner::sole()->GameRunId, GameWinner::sole()->ChosenById]);
    }

    public function test_the_game_comes_in_bahasa_melayu_under_the_same_name(): void
    {
        $this->figures();

        $this->get('/public/game/case-file?lang=ms')->assertOk()
            ->assertSee('<title>Case File · '.config('app.name').'</title>', false)
            ->assertSee('data-start="'.route('public.game.case-file.start', ['lang' => 'ms']).'"', false)
            ->assertSeeInOrder(['Suspek', 'Pilih suspek', 'Sulit', 'Cara bermain', 'Setiap kes ialah sebuah daerah polis yang misteri', 'Detektif', 'Buka fail', 'Laporan kes'])
            ->assertSee('"Guilty":"Bersalah"', false);

        // The crime in the player's language.
        $this->postJson('/public/game/case-file/start?lang=ms', ['name' => 'Siti'])->assertOk()
            ->assertJsonPath('asked.clues.rap.type', __('Break-in', [], 'ms'));
        $this->assertNotSame('Break-in', __('Break-in', [], 'ms'));

        $this->postJson('/public/game/case-file/next?lang=ms', ['token' => 'made-up'])
            ->assertJsonPath('message', 'Permainan ini tidak dijumpai. Mulakan yang baharu.');
    }

    /**
     * A live game of Case File hosted by an administrator, from the Game scores page.
     */
    private function hosted(int $rounds = 3): LiveGame
    {
        $this->figures();
        $this->signIn();
        $this->post('/game-scores/store-live', ['game' => 'case-file', 'rounds' => $rounds])->assertRedirect();

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
        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])->getJson("/api/game/live/{$game->Id}");
    }

    /**
     * Accuse a suspect in the round being played: the right one, or the one after it.
     *
     * @param  array{player: int, token: string}  $player
     */
    private function pick(LiveGame $game, array $player, bool $right = true): TestResponse
    {
        $game->refresh();
        $culprit = $game->Cards[$game->Round - 1]['culprit'];

        return $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => $game->Round, 'suspect' => $right ? $culprit : ($culprit + 1) % 6]);
    }

    private function host(LiveGame $game, string $action = 'state'): TestResponse
    {
        return $action === 'state'
            ? $this->getJson("/game-scores/live-state/{$game->Id}")
            : $this->postJson("/game-scores/live-{$action}/{$game->Id}");
    }

    public function test_an_administrator_hosts_a_live_game_of_case_file(): void
    {
        $this->freezeTime();
        $game = $this->hosted();

        // A clue every 6 seconds, the last open for as long: 30 seconds a round.
        $this->assertSame(['case-file', LiveGame::Lobby, 3, 30], [$game->Game, $game->Status, $game->Rounds, $game->Seconds]);
        $this->assertCount(3, $game->Cards);
        $this->get('/game-scores')->assertSeeInOrder(['<option value="case-file"', 'Case File', 'Live games', $game->spacedCode(), 'Case File', '3, not started'], false);

        $this->get("/game-scores/live/{$game->Id}")->assertOk()
            ->assertSee('alt="Case File" lang="en"', false)
            ->assertSee('data-case-file="'.versioned_asset(config('map.boundaries')).'"', false)
            ->assertSee('data-case-screen data-mode="host"', false)
            ->assertSee(versioned_asset('modules/game/case-screen.js'), false)
            ->assertSee('a clue opens on the desk every 6 seconds, and the sooner a right')
            ->assertDontSee('data-cs="accuse"', false)
            ->assertDontSee('data-cs-view="cover"', false)
            ->assertDontSee('data-game-card', false);

        // The phone's page is ready for it.
        $this->get('/public/game/live')->assertOk()
            ->assertSee('<div data-live-game="case-file" hidden>', false)
            ->assertSee('Pick the district and accuse them: the sooner, the fewer clues open and the more points, from 1,000 down to 150.')
            ->assertSeeInOrder(['data-live-game="case-file" data-live="case"', 'data-case-screen data-mode="phone"', 'data-cs="suspects"', 'data-cs="accuse"'], false)
            ->assertDontSee('data-cs-view="cover"', false);
    }

    public function test_live_clues_open_one_by_one_and_a_right_pick_scores_what_it_was_worth(): void
    {
        $this->freezeTime();
        $game = $this->hosted();
        $players = collect(['Siti', 'Ali', 'Chong', 'Devi'])->mapWithKeys(fn (string $name) => [$name => $this->join($game, $name)]);

        // Getting ready: the suspects, no clues yet.
        $this->host($game, 'next')->assertJson(['game' => 'case-file', 'phase' => 'ready', 'round' => 1, 'seconds' => 30, 'clues' => [], 'shown' => 0]);
        $case = $game->fresh()->Cards[0];
        $this->check($game, $players['Siti'])->assertJson(['phase' => 'ready', 'suspects' => $case['suspects'], 'clues' => [], 'guess' => null])
            ->assertJsonMissingPath('answer');
        $this->pick($game, $players['Siti'])->assertStatus(409);

        // The rap sheet as it opens; the trend report 6 seconds on, and so on.
        $this->travel(3)->seconds();
        $this->check($game, $players['Siti'])->assertJson(['phase' => 'question', 'shown' => 1, 'worth' => 1000, 'every' => 6])
            ->assertJsonPath('clues.rap.crimes', $case['clues']['rap']['crimes'])
            ->assertJsonMissingPath('clues.trend')->assertJsonMissingPath('answer');

        // Right on the rap sheet: all of it.
        $this->pick($game, $players['Siti'])->assertOk()->assertExactJson(['received' => true]);
        $this->check($game, $players['Siti'])->assertJson(['guess' => $case['culprit']]);

        // A suspect is one of the six.
        $this->withHeaders(['X-Player' => $players['Ali']['player'], 'X-Player-Token' => $players['Ali']['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => 1, 'suspect' => 6])->assertUnprocessable()->assertJsonValidationErrors('suspect');

        // Two clues open: 850. Four: 450, and Chong wrong. After the time, too late.
        $this->travel(6)->seconds();
        $this->host($game)->assertJson(['shown' => 2, 'worth' => 850])->assertJsonStructure(['clues' => ['rap', 'trend']]);
        $this->pick($game, $players['Ali'])->assertOk();
        $this->travel(12)->seconds();
        $this->check($game, $players['Devi'])->assertJson(['shown' => 4, 'worth' => 450]);
        $this->pick($game, $players['Devi'])->assertOk();
        $this->pick($game, $players['Chong'], right: false)->assertOk();

        $this->assertSame(
            ['Siti' => 1000, 'Ali' => 850, 'Chong' => 0, 'Devi' => 450],
            $game->players()->orderBy('Id')->pluck('Score', 'PlayerName')->all(),
        );

        // Everyone has answered: every clue, who it was, and how each did.
        $this->check($game, $players['Chong'])->assertJson([
            'phase' => 'reveal', 'shown' => 5, 'answer' => $case['culprit'], 'guess' => ($case['culprit'] + 1) % 6, 'result' => ['correct' => false, 'points' => 0],
        ])->assertJsonStructure(['clues' => ['rap', 'trend', 'rank', 'photo', 'tip']]);

        // The big screen: how many picked each, how many got it, and the quickest.
        $picks = array_fill(0, 6, 0);
        $picks[$case['culprit']] = 3;
        $picks[($case['culprit'] + 1) % 6] = 1;
        $this->host($game)->assertJson([
            'phase' => 'reveal', 'answer' => $case['culprit'], 'picks' => $picks, 'solved' => 3, 'fastest' => ['name' => 'Siti', 'seconds' => 0],
            'leaderboard' => [['name' => 'Siti', 'score' => 1000], ['name' => 'Ali', 'score' => 850], ['name' => 'Devi', 'score' => 450]],
        ]);
    }
}
