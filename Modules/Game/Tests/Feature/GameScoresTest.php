<?php

namespace Modules\Game\Tests\Feature;

use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Game\Entities\GameRun;
use Modules\Game\Entities\GameWinner;
use Tests\TestCase;

class GameScoresTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A finished game: who played it, its score, when it started, and how many seconds it took to reach the score.
     */
    private function played(string $name, int $score, string $startedAt, int $seconds = 60): GameRun
    {
        $card = ['state' => 'Johor', 'district' => 'Kluang', 'category' => 'property', 'type' => 'break_in', 'year' => 2023, 'crimes' => 140];
        $started = Carbon::parse($startedAt);

        return GameRun::create([
            'Token' => bin2hex(random_bytes(16)),
            'PlayerName' => $name,
            'PlayerKey' => GameRun::keyFor($name),
            'Score' => $score,
            'Current' => $card,
            'Next' => $card,
            'AskedAt' => $started,
            'ScoredAt' => $score > 0 ? $started->copy()->addSeconds($seconds) : null,
            'StartedAt' => $started,
            'EndedAt' => $started->copy()->addSeconds($seconds + 5),
        ]);
    }

    public function test_the_game_scores_page_is_in_the_menu_for_admin_only(): void
    {
        $link = MenuItem::firstWhere('Url', '/game-scores');
        $this->assertSame(['Game scores', 'emoji_events', false], [$link->Label, $link->Icon, (bool) $link->VisibleToEveryone]);
        $this->assertSame(['ADMIN'], $link->roles->pluck('Name')->all());

        $this->signIn();
        $this->get('/game-scores')->assertOk()
            ->assertSee('<h1>Game scores</h1>', false)
            ->assertSee($this->sideLink('Game scores', 'page', 'emoji_events'), false)
            ->assertSee('<a class="btn btn-primary" href="'.route('public.game').'" target="_blank" rel="noopener">', false)
            ->assertSee('No games yet. Share');
    }

    public function test_only_admin_sees_the_scores(): void
    {
        $run = $this->played('Siti', 7, '2026-10-05 10:00');
        $winner = GameWinner::create(['GameRunId' => $run->Id, 'PlayerName' => 'Siti', 'Score' => 7, 'ChosenByName' => 'Admin', 'ChosenAt' => now()]);

        $this->get('/game-scores')->assertRedirect('/login');

        $this->signIn(admin: false);
        $this->get('/dashboard')->assertOk()->assertDontSee('Game scores');
        $this->get('/game-scores')->assertForbidden();
        $this->post('/game-scores/store-winner', ['run' => $run->Id])->assertForbidden();
        $this->delete("/game-scores/destroy-winner/{$winner->Id}")->assertForbidden();

        $this->assertDatabaseCount('GameWinners', 1);
    }

    public function test_the_leaderboard_has_each_players_best_game_best_first(): void
    {
        $this->signIn();
        $this->played('Siti', 5, '2026-10-05 09:00');
        $this->played('siti', 9, '2026-10-06 09:00', seconds: 125);
        // The same score as Siti's best, reached sooner after starting, but later in the day: Siti's came first.
        $this->played('Ali', 9, '2026-10-06 10:00', seconds: 30);
        $this->played('Bob', 0, '2026-10-07 09:00');
        $this->played('Chong', 3, '2026-10-07 10:00', seconds: 45);

        $this->get('/game-scores')->assertOk()
            ->assertSee('4 players, 5 games.')
            ->assertSee('Leaderboard <span class="scores-period">All games</span>', false)
            ->assertSeeInOrder([
                '<span class="scores-rank">1</span>', 'siti', '<td class="scores-score">9</td>', '<td>2</td>', '2 min 5 s', '06 Oct 2026, 09:02',
                '<span class="scores-rank">2</span>', 'Ali', '<td class="scores-score">9</td>', '<td>1</td>', '30 s',
                '<span class="scores-rank">3</span>', 'Chong', '<td class="scores-score">3</td>', '45 s',
                '<span class="scores-rank">4</span>', 'Bob', '<td class="scores-score">0</td>', '—', '07 Oct 2026, 09:00',
            ], false)
            ->assertSee('data-confirm="Are you sure you want to choose siti as the winner, with a score of 9?" data-confirm-kind="save"', false);
    }

    public function test_the_dates_narrow_the_games(): void
    {
        $this->signIn();
        $this->played('Siti', 9, '2026-09-30 23:30');
        $this->played('Ali', 4, '2026-10-01 00:10');
        $this->played('Bob', 6, '2026-10-31 23:59');
        $this->played('Chong', 8, '2026-11-01 00:00');

        $this->get('/game-scores?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertSee('value="2026-10-01"', false)
            ->assertSee('Leaderboard <span class="scores-period">1 Oct 2026 to 31 Oct 2026</span>', false)
            ->assertSee('2 players, 2 games.')
            ->assertSeeInOrder(['Bob', 'Ali'])
            ->assertDontSee('Siti')
            ->assertDontSee('Chong')
            ->assertSee('<input type="hidden" name="from" value="2026-10-01" />', false);

        // The other way round is the same dates; one day, or from a day on, works too.
        $this->get('/game-scores?from=2026-10-31&to=2026-10-01')->assertSee('2 players, 2 games.');
        $this->get('/game-scores?from=2026-11-01&to=2026-11-01')->assertSee('<span class="scores-period">1 Nov 2026</span>', false)->assertSee('1 player, 1 game.');
        $this->get('/game-scores?from=2026-10-31')->assertSee('From 31 Oct 2026')->assertSeeInOrder(['Chong', 'Bob']);
        $this->get('/game-scores?from=2026-12-01')->assertSee('No games between these dates.');

        // Anything but a day is left out.
        $this->get('/game-scores?from=2026-02-30&to=yesterday')->assertSee('All games')->assertSee('4 players, 4 games.');
    }

    public function test_choosing_and_removing_a_winner(): void
    {
        $me = $this->signIn();
        $best = $this->played('Siti', 9, '2026-10-06 09:00');
        $this->played('Ali', 4, '2026-10-07 09:00');

        $this->get('/game-scores')->assertSee('None chosen yet.');

        $this->post('/game-scores/store-winner', ['run' => $best->Id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertRedirect(route('game-scores', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertSessionHas('message', 'Chose Siti as the winner, with a score of 9.');

        $winner = GameWinner::sole();
        $this->assertSame(
            [$best->Id, 'Siti', 9, '2026-10-01', '2026-10-31', $me->Id, 'Signed In'],
            [$winner->GameRunId, $winner->PlayerName, $winner->Score, $winner->FromDate->format('Y-m-d'), $winner->ToDate->format('Y-m-d'), $winner->ChosenById, $winner->ChosenByName],
        );

        // A badge on the leaderboard instead of the button, and in the winners.
        $this->get('/game-scores')->assertOk()
            ->assertSeeInOrder(['Siti', 'Winner', 'Ali', 'Choose as winner', 'id="winners-heading"', 'Siti', '<td class="scores-score">9</td>', '1 Oct 2026 to 31 Oct 2026', 'Signed In'], false)
            ->assertDontSee('choose Siti as the winner')
            ->assertSee('data-confirm="Are you sure you want to take Siti off the winners?" data-confirm-kind="delete"', false);

        $this->delete("/game-scores/destroy-winner/{$winner->Id}", ['from' => '2026-10-01'])
            ->assertRedirect(route('game-scores', ['from' => '2026-10-01']))
            ->assertSessionHas('message', 'Took Siti off the winners.');
        $this->assertDatabaseCount('GameWinners', 0);
        $this->assertDatabaseCount('GameRuns', 2);

        $this->post('/game-scores/store-winner', ['run' => 999])->assertSessionHasErrors('run');
        $this->assertDatabaseCount('GameWinners', 0);
    }
}
