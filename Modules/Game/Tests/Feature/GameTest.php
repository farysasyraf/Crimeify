<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Lottery;
use Modules\Game\Entities\GameRun;
use Modules\Game\Support\Cards;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Police district figures, each [state, district, category, type, year, crimes], with Labuan's district filed
     * under Sabah, as data.gov.my has it.
     *
     * @param  list<array{string, string, string, string, int, int}>  $rows
     */
    private function figures(array $rows = [
        ['Johor', 'Batu Pahat', 'property', 'break_in', 2023, 100],
        ['Johor', 'Kluang', 'property', 'break_in', 2023, 140],
        ['Sabah', 'W.P. Labuan', 'property', 'theft_vehicle_motorcycle', 2022, 400],
    ]): void
    {
        PoliceDistrict::create(['State' => 'Johor', 'Name' => 'Batu Pahat', 'Region' => 'MY-01', 'Latitude' => 1.85, 'Longitude' => 102.93]);
        PoliceDistrict::create(['State' => 'Johor', 'Name' => 'Kluang', 'Region' => 'MY-01', 'Latitude' => 2.03, 'Longitude' => 103.31]);
        PoliceDistrict::create(['State' => 'Sabah', 'Name' => 'W.P. Labuan', 'Region' => 'MY-15', 'Latitude' => 5.27, 'Longitude' => 115.24]);

        foreach ($rows as [$state, $district, $category, $type, $year, $crimes]) {
            CrimeStat::create(['State' => $state, 'District' => $district, 'Category' => $category, 'Type' => $type, 'Year' => $year, 'Crimes' => $crimes]);
        }
    }

    /**
     * A card as a game keeps it.
     *
     * @return array{state: string, district: string, category: string, type: string, year: int, crimes: int}
     */
    private function card(string $district, int $crimes, string $type = 'break_in'): array
    {
        return ['state' => 'Johor', 'district' => $district, 'category' => 'property', 'type' => $type, 'year' => 2023, 'crimes' => $crimes];
    }

    /**
     * A game under way, with Batu Pahat's 100 break-ins shown and Kluang's 140 to guess.
     */
    private function game(int $score = 0): GameRun
    {
        return GameRun::create([
            'Token' => 'a-token', 'PlayerName' => 'Siti', 'PlayerKey' => 'siti', 'Score' => $score,
            'Current' => $this->card('Batu Pahat', 100), 'Next' => $this->card('Kluang', 140),
            'AskedAt' => now(), 'StartedAt' => now(),
        ]);
    }

    public function test_anyone_can_open_the_game_without_logging_in(): void
    {
        $this->figures();

        $this->get('/public/game')->assertOk()
            ->assertSee('<title>Higher or Lower · '.config('app.name').'</title>', false)
            ->assertSee('<h1>Higher or Lower</h1>', false)
            ->assertSee('class="public-link active" aria-current="page"', false)
            ->assertSee('data-guess="'.route('public.game.guess').'" data-seconds="20"', false)
            ->assertSee('action="'.route('public.game.start').'" data-game-start', false)
            ->assertSee('Each right guess scores a point. One wrong guess, or 20 seconds without one, ends the game.')
            ->assertSee(versioned_asset('modules/game/game.js'), false);

        // In the public pages' links, after the map.
        $this->get('/public/dashboard')->assertSeeInOrder([route('public.map'), 'Map', route('public.game'), 'Game'], false);

        $this->assertGuest();
    }

    public function test_with_no_figures_the_game_says_so(): void
    {
        $this->get('/public/game')->assertOk()
            ->assertSee('There are no crime figures to play with yet.')
            ->assertDontSee('data-game-start', false);

        $this->postJson('/public/game/start', ['name' => 'Siti'])->assertStatus(503)
            ->assertJsonPath('message', 'There are no crime figures to play with yet.');
        $this->assertDatabaseCount('GameRuns', 0);
    }

    public function test_starting_shows_the_first_card_and_hides_the_next_ones_count(): void
    {
        $this->figures();

        $started = $this->postJson('/public/game/start', ['name' => '  Siti   Aminah '])->assertOk()
            ->assertJsonPath('score', 0)
            ->assertJsonPath('seconds', 20)
            ->json();

        $run = GameRun::sole();
        $this->assertSame($run->Token, $started['token']);
        $this->assertSame(['Siti Aminah', 'siti aminah', 0, null], [$run->PlayerName, $run->PlayerKey, $run->Score, $run->EndedAt]);

        // The card shown has its count; the one to guess doesn't, and has another.
        $this->assertSame($run->Current['crimes'], $started['shown']['crimes']);
        $this->assertArrayNotHasKey('crimes', $started['next']);
        $this->assertNotSame($run->Current['crimes'], $run->Next['crimes']);
        $this->assertSame(['type', 'district', 'place', 'year', 'icon'], array_keys($started['next']));
    }

    public function test_a_card_shows_the_crime_type_and_the_region_its_pin_is_in(): void
    {
        $this->figures();
        $cards = app(Cards::class);

        $this->assertSame(
            ['type' => 'Motorcycle theft', 'district' => 'W.P. Labuan', 'place' => 'Labuan', 'year' => 2022, 'icon' => 'two_wheeler', 'crimes' => 400],
            $cards->face(['state' => 'Sabah', 'district' => 'W.P. Labuan', 'category' => 'property', 'type' => 'theft_vehicle_motorcycle', 'year' => 2022, 'crimes' => 400], withCount: true),
        );
        $this->assertSame(
            ['type' => 'Break-in', 'district' => 'Batu Pahat', 'place' => 'Johor', 'year' => 2023, 'icon' => 'door_front'],
            $cards->face($this->card('Batu Pahat', 100), withCount: false),
        );
    }

    public function test_the_name_is_checked(): void
    {
        $this->figures();

        $this->postJson('/public/game/start', [])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Type your name to play.');
        $this->postJson('/public/game/start', ['name' => '   '])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Type your name to play.');
        $this->postJson('/public/game/start', ['name' => str_repeat('a', 41)])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Your name can be at most 40 characters.');
        $this->postJson('/public/game/start', ['name' => '<b>Siti</b>'])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', "Your name can have letters, numbers and spaces, and . ' @ / or -.");
        $this->postJson('/public/game/start', ['name' => '-Siti'])->assertUnprocessable();
        $this->postJson('/public/game/start', ['name' => ['Siti']])->assertUnprocessable();
        $this->assertDatabaseCount('GameRuns', 0);

        // Names as people have them.
        foreach (['Nur Aisyah bt. Ahmad', 'Muthu a/l Rajan', 'O’Neil', 'siti@pdrm', 'Ah-Kow 2', 'Lim 林'] as $name) {
            $this->postJson('/public/game/start', ['name' => $name])->assertOk();
        }
        $this->assertSame(6, GameRun::count());
    }

    public function test_a_right_guess_scores_and_deals_the_next_card(): void
    {
        // Kluang's card to guess, and the only other: Labuan's.
        $this->figures([
            ['Johor', 'Kluang', 'property', 'break_in', 2023, 140],
            ['Sabah', 'W.P. Labuan', 'property', 'theft_vehicle_motorcycle', 2022, 400],
        ]);
        $run = $this->game();

        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => 'higher'])->assertOk()
            ->assertExactJson([
                'right' => true, 'late' => false, 'answer' => 140, 'score' => 1, 'over' => false,
                'next' => ['type' => 'Motorcycle theft', 'district' => 'W.P. Labuan', 'place' => 'Labuan', 'year' => 2022, 'icon' => 'two_wheeler'],
            ]);

        // Kluang's card is the one shown now.
        $run->refresh();
        $this->assertSame([1, 'Kluang', 400, null], [$run->Score, $run->Current['district'], $run->Next['crimes'], $run->EndedAt]);
        $this->assertNotNull($run->ScoredAt);

        // Labuan's 400 is higher than Kluang's 140, and Kluang's comes again after it.
        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => 'higher'])->assertOk()
            ->assertJsonPath('right', true)->assertJsonPath('answer', 400)->assertJsonPath('score', 2)->assertJsonPath('next.district', 'Kluang');
    }

    public function test_a_wrong_guess_ends_the_game_and_keeps_its_score(): void
    {
        $this->figures();
        $run = $this->game(score: 4);

        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => 'lower'])->assertOk()
            ->assertExactJson(['right' => false, 'late' => false, 'answer' => 140, 'score' => 4, 'over' => true]);
        $this->assertSame(4, $run->fresh()->Score);
        $this->assertNotNull($run->fresh()->EndedAt);

        // It can't be played on.
        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => 'higher'])->assertStatus(409)
            ->assertJsonPath('message', 'This game has ended. Start a new one.');
        $this->assertSame(4, $run->fresh()->Score);
    }

    public function test_running_out_of_time_ends_the_game(): void
    {
        $this->figures();

        // No guess: the page's clock ran out.
        $this->game();
        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => null])->assertOk()
            ->assertJsonPath('right', false)->assertJsonPath('late', true)->assertJsonPath('over', true);

        // A right guess, after the 20 seconds but within the 4 more allowed for it to arrive. (The database keeps
        // whole seconds, so 24 could be a moment over.)
        GameRun::query()->delete();
        $this->game();
        $this->travel(23)->seconds();
        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => 'higher'])->assertOk()
            ->assertJsonPath('right', true);

        $this->travel(25)->seconds();
        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => 'higher'])->assertOk()
            ->assertJsonPath('right', false)->assertJsonPath('late', true)->assertJsonPath('score', 1);
    }

    public function test_a_guess_needs_a_game(): void
    {
        $this->figures();
        $this->game();

        $this->postJson('/public/game/guess', ['token' => 'made-up', 'guess' => 'higher'])->assertNotFound()
            ->assertJsonPath('message', "This game wasn't found. Start a new one.");
        $this->postJson('/public/game/guess', ['token' => 'a-token', 'guess' => 'sideways'])->assertUnprocessable();
        $this->postJson('/public/game/guess', ['guess' => 'higher'])->assertUnprocessable();
        $this->assertSame(0, GameRun::sole()->Score);
    }

    public function test_the_cards_are_police_districts_counts_of_each_crime_type(): void
    {
        $this->figures([
            // Not cards: Malaysia's and a state's totals, a category's total, and a count under 5.
            ['Malaysia', 'All', 'property', 'break_in', 2023, 900],
            ['Johor', 'All', 'property', 'break_in', 2023, 800],
            ['Johor', 'Batu Pahat', 'property', 'all', 2023, 700],
            ['Johor', 'Batu Pahat', 'assault', 'murder', 2023, 4],
            // Cards.
            ['Johor', 'Batu Pahat', 'property', 'break_in', 2023, 100],
            ['Johor', 'Kluang', 'assault', 'murder', 2023, 5],
        ]);
        $cards = app(Cards::class);

        foreach (range(1, 10) as $deal) {
            $first = $cards->first();
            $this->assertContains([$first['district'], $first['crimes']], [['Batu Pahat', 100], ['Kluang', 5]]);
            $this->assertSame($first['crimes'] === 100 ? 5 : 100, $cards->after($first, 0)['crimes']);
        }

        // Nothing to play with when every count is the same.
        CrimeStat::where('Crimes', 5)->update(['Crimes' => 100]);
        $this->assertFalse($cards->ready());
        $this->assertNull($cards->after($this->card('Batu Pahat', 100), 0));
    }

    public function test_the_next_count_is_higher_or_lower_and_gets_closer_as_the_score_goes_up(): void
    {
        $this->figures([
            ['Johor', 'Batu Pahat', 'property', 'break_in', 2023, 100],
            ['Johor', 'Kluang', 'property', 'break_in', 2023, 140],
            ['Johor', 'Kluang', 'property', 'theft_other', 2023, 120],
            ['Johor', 'Muar', 'property', 'break_in', 2023, 400],
            ['Johor', 'Muar', 'property', 'theft_other', 2023, 80],
            ['Johor', 'Segamat', 'property', 'theft_other', 2023, 30],
        ]);
        $cards = app(Cards::class);
        $shown = $this->card('Batu Pahat', 100);
        $deals = fn (array $card, int $score) => collect(range(1, 100))->map(fn () => $cards->after($card, $score)['crimes'])->unique()->sort()->values()->all();

        // Lower, of any type: up to 5 times fewer at first, then only up to 1.5 times from a score of 20.
        Lottery::alwaysLose(function () use ($deals, $shown) {
            $this->assertSame([30, 80], $deals($shown, 0));
            $this->assertSame([80], $deals($shown, 20));

            // None lower: higher.
            $this->assertSame([80, 100, 120, 140], $deals($this->card('Segamat', 30, 'theft_other'), 0));
        });

        // Higher, of the same type, when there's one.
        Lottery::alwaysWin(function () use ($deals, $shown) {
            $this->assertSame([140, 400], $deals($shown, 0));
            $this->assertSame([140], $deals($shown, 20));

            // None that close either way: any other count.
            $this->assertSame([30, 80, 100, 120, 140, 400], $deals($this->card('Batu Pahat', 5000), 20));
        });
    }

    public function test_some_crime_types_are_left_out(): void
    {
        $this->figures([
            ['Johor', 'Batu Pahat', 'assault', 'rape', 2023, 100],
            ['Johor', 'Kluang', 'assault', 'murder', 2023, 50],
            ['Johor', 'Muar', 'assault', 'causing_injury', 2023, 70],
        ]);
        $cards = app(Cards::class);

        $this->assertSame(['rape'], config('game.left_out'));
        foreach (range(1, 10) as $deal) {
            $this->assertNotSame('rape', $cards->first()['type']);
        }

        config(['game.left_out' => ['rape', 'murder']]);
        $this->assertFalse($cards->ready());
    }

    public function test_the_game_comes_in_bahasa_melayu(): void
    {
        $this->figures([
            ['Johor', 'Kluang', 'property', 'break_in', 2023, 140],
            ['Sabah', 'W.P. Labuan', 'property', 'theft_vehicle_motorcycle', 2022, 400],
        ]);

        $this->get('/public/game?lang=ms')->assertOk()
            ->assertSee('<h1>Tinggi atau Rendah</h1>', false)
            ->assertSeeInOrder(['Papan Pemuka', 'Peta', 'Permainan'])
            ->assertSee('data-guess="'.route('public.game.guess', ['lang' => 'ms']).'"', false)
            ->assertSee('action="'.route('public.game.start', ['lang' => 'ms']).'"', false)
            ->assertSee('"Higher or lower than :count?":"Lebih tinggi atau lebih rendah daripada :count?"', false);

        $this->game();
        $this->postJson('/public/game/guess?lang=ms', ['token' => 'a-token', 'guess' => 'higher'])
            ->assertJsonPath('next.type', 'Curi motosikal');

        $this->postJson('/public/game/start?lang=ms', [])->assertJsonPath('errors.name.0', 'Taip nama anda untuk bermain.');
    }
}
