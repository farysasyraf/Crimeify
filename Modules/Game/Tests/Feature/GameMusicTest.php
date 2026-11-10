<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Game\Entities\LiveGame;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameMusicTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each game's page and its track's title.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function games(): array
    {
        return [
            'Higher or Lower' => ['/public/game/higher-or-lower', 'higher-or-lower', 'Hustle'],
            'DistrictGuessr' => ['/public/game/districtguessr', 'districtguessr', 'Pamgaea'],
            'Skyline' => ['/public/game/skyline', 'skyline', 'Ethernight Club'],
            'Case File' => ['/public/game/case-file', 'case-file', '8bit Dungeon Level'],
            'Line-Up' => ['/public/game/line-up', 'line-up', 'Hard Boiled'],
        ];
    }

    #[DataProvider('games')]
    public function test_each_game_has_its_own_music_with_its_switch_and_its_credit(string $page, string $game, string $title): void
    {
        $track = config("game.music.tracks.{$game}");

        $this->assertFileExists(public_path($track['file']));
        $this->assertSame($title, $track['title']);

        $this->get($page)->assertOk()
            // The switch, beside All games, and its script once.
            ->assertSeeInOrder(['class="game-head-actions"', 'data-game-music="'.versioned_asset($track['file']).'"', 'data-volume="0.35"', 'aria-pressed="true" hidden'], false)
            ->assertSee(versioned_asset('modules/game/music.js'), false)
            // The credit, as the licence asks.
            ->assertSee('Music: “'.$title.'” Kevin MacLeod (incompetech.com), licensed under <a href="https://creativecommons.org/licenses/by/4.0/" rel="license">Creative Commons: By Attribution 4.0</a>.', false);

        $this->assertSame(1, substr_count($this->get($page)->getContent(), versioned_asset('modules/game/music.js')));
    }

    public function test_the_music_comes_in_bahasa_melayu(): void
    {
        $this->get('/public/game/line-up?lang=ms')->assertOk()
            ->assertSee('Muzik: “Hard Boiled” Kevin MacLeod (incompetech.com), dilesenkan di bawah <a href="https://creativecommons.org/licenses/by/4.0/" rel="license">Creative Commons: By Attribution 4.0</a>.', false)
            ->assertSeeInOrder(['data-game-music', 'Muzik'], false)
            ->assertSee('"Turn the music off":"Matikan muzik"', false);
    }

    public function test_a_live_game_plays_its_music_on_the_big_screen_not_the_phones(): void
    {
        $this->get('/public/game/live')->assertOk()->assertDontSee('data-game-music', false)->assertDontSee('game-music-credit', false);

        $host = $this->signIn();
        $game = LiveGame::create([
            'Game' => 'skyline', 'Mode' => 'classic', 'Difficulty' => 'hard', 'Code' => '123456', 'HostId' => $host->Id, 'HostName' => $host->Name,
            'Rounds' => 3, 'Seconds' => 30, 'Cards' => [], 'Status' => LiveGame::Lobby, 'Round' => 0, 'CreatedAt' => now(),
        ]);

        $this->get("/game-scores/live/{$game->Id}")->assertOk()
            ->assertSeeInOrder(['class="live-host-music"', 'data-game-music="'.versioned_asset('modules/game/music/skyline.mp3').'"'], false)
            ->assertSee('Music: “Ethernight Club” Kevin MacLeod (incompetech.com)', false);
    }
}
