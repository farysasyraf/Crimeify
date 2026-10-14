<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Break-ins in two police districts, for a round of Higher or Lower.
     */
    private function figures(): void
    {
        config(['game.fewest_counts' => 2]);

        foreach ([['Batu Pahat', 100], ['Kluang', 140]] as [$district, $crimes]) {
            PoliceDistrict::create(['State' => 'Johor', 'Name' => $district, 'Region' => 'MY-01', 'Latitude' => 1.85, 'Longitude' => 102.93]);
            CrimeStat::create(['State' => 'Johor', 'District' => $district, 'Category' => 'property', 'Type' => 'break_in', 'Year' => 2023, 'Crimes' => $crimes]);
        }
    }

    public function test_the_games_page_lists_the_games_as_tiles_higher_or_lower_first(): void
    {
        $this->figures();

        $this->get('/public/game')->assertOk()
            ->assertSee('<title>Games · '.config('app.name').'</title>', false)
            ->assertSee('<a href="'.route('public.game').'" class="public-link active" aria-current="page">', false)
            ->assertSee(versioned_asset('modules/game/library.js'), false)
            ->assertSee("--doodles: url('".versioned_asset('modules/game/doodles.svg')."')", false)
            // The tiles, in order, each opening its game; the first chosen.
            ->assertSeeInOrder([
                'href="'.route('public.game.higher-or-lower').'" id="tile-higher-or-lower"', 'class="library-tile is-selected"',
                versioned_asset('modules/game/tile-higher-or-lower.svg'), '<span class="library-tile-name">Higher or Lower</span>',
                'href="'.route('public.game.live').'" id="tile-live"', '<span class="library-tile-name">Join a live game</span>',
                'href="#game-soon" id="tile-soon"', '<span class="library-tile-name">More games coming soon</span>',
            ], false)
            // Higher or Lower's title, buttons, a round of it, and its cards; then the others.
            ->assertSeeInOrder([
                '<section class="library-game is-shown" id="game-higher-or-lower"',
                'alt="Higher or Lower" lang="en"',
                'Which police district had more crime?',
                'href="'.route('public.game.higher-or-lower').'"', 'Play',
                'href="'.route('public.game.live').'"', 'Join a live game',
                'class="library-sample" aria-hidden="true"', 'Break-in', '<strong>?</strong>',
                'On your own', 'A point for each right guess, 20 seconds a card.', 'Live, with a room', 'Real figures',
                '<section class="library-game" id="game-live"', 'Join with a code',
                '<section class="library-game" id="game-soon"', 'More games with Malaysia&#039;s crime figures are on the way.',
            ], false);
    }

    public function test_without_figures_the_games_are_listed_without_a_round(): void
    {
        $this->get('/public/game')->assertOk()
            ->assertSee('id="game-higher-or-lower"', false)
            ->assertDontSee('class="library-sample"', false);
    }

    public function test_the_games_page_comes_in_bahasa_melayu(): void
    {
        $this->figures();

        $this->get('/public/game?lang=ms')->assertOk()
            ->assertSee('<title>Permainan · '.config('app.name').'</title>', false)
            ->assertSeeInOrder(['Papan Pemuka', 'Peta', 'Permainan'])
            // The game's name stays in English, as its title is drawn.
            ->assertSeeInOrder(['Higher or Lower', 'Sertai permainan langsung', 'Lebih banyak permainan akan datang'])
            ->assertSeeInOrder(['Main', 'Sendirian', 'Angka sebenar', 'Sertai dengan kod', 'Pecah rumah']);
    }
}
