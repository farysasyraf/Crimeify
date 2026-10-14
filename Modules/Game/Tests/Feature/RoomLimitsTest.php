<?php

namespace Modules\Game\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Game\Entities\LiveGame;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

// A room of colleagues on one Wi-Fi shares one IP address, as every request in a test does: the games' rate limits let
// them all play at once, and limit each player on their own.
class RoomLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['game.live.store' => 'array', 'game.fewest_counts' => 2]);
        $this->freezeTime();

        foreach ([['Batu Pahat', 100], ['Kluang', 140], ['Muar', 60]] as [$district, $crimes]) {
            PoliceDistrict::create(['State' => 'Johor', 'Name' => $district, 'Region' => 'MY-01', 'Latitude' => 1.85, 'Longitude' => 102.93]);
            CrimeStat::create(['State' => 'Johor', 'District' => $district, 'Category' => 'property', 'Type' => 'break_in', 'Year' => 2023, 'Crimes' => $crimes]);
        }
    }

    public function test_a_room_joins_and_answers_a_live_game_from_one_address(): void
    {
        $this->signIn();
        $this->post('/game-scores/store-live', ['rounds' => 3])->assertRedirect();
        $game = LiveGame::sole();

        // 60 players open the page and join, more than the public pages' 120 requests a minute between them.
        $players = collect(range(1, 60))->map(function (int $n) use ($game) {
            $this->get('/public/game/live?code='.$game->Code)->assertOk();

            return $this->postJson('/api/game/live/join', ['code' => $game->Code, 'name' => "Player {$n}"])->assertOk()->json();
        });

        $this->postJson("/game-scores/live-next/{$game->Id}")->assertOk();
        $this->travel(3)->seconds();
        $answer = fn (array $player) => $this->withHeaders(['X-Player' => $player['player'], 'X-Player-Token' => $player['token']])
            ->postJson("/api/game/live/{$game->Id}/answer", ['round' => 1, 'guess' => 'higher']);

        $players->each(fn (array $player) => $answer($player)->assertOk());
        $this->assertSame(60, $game->answers()->count());

        // One player sending again and again is limited on their own, at 30 a minute; the others aren't.
        foreach (range(2, 30) as $try) {
            $answer($players[0])->assertStatus(409);
        }
        $answer($players[0])->assertTooManyRequests();
        $answer($players[1])->assertStatus(409);
    }

    public function test_a_room_plays_solo_games_at_once_from_one_address(): void
    {
        // 40 games started and 120 guesses, more than the public pages' 120 requests a minute between them.
        $tokens = collect(range(1, 40))->map(fn (int $n) => $this->postJson('/public/game/start', ['name' => "Player {$n}"])->assertOk()->json('token'));
        $this->travel(3)->seconds();

        foreach ($tokens as $token) {
            foreach (range(1, 3) as $guess) {
                $this->assertNotSame(429, $this->postJson('/public/game/guess', ['token' => $token, 'guess' => 'higher'])->status());
            }
        }

        // One game's guesses are limited on their own, at 90 a minute; the others' aren't.
        foreach (range(4, 90) as $guess) {
            $this->postJson('/public/game/guess', ['token' => $tokens[0], 'guess' => 'higher']);
        }
        $this->postJson('/public/game/guess', ['token' => $tokens[0], 'guess' => 'higher'])->assertTooManyRequests();
        $this->assertNotSame(429, $this->postJson('/public/game/guess', ['token' => $tokens[1], 'guess' => 'higher'])->status());

        // The pages, by address, 600 a minute.
        foreach (range(1, 130) as $load) {
            $this->get('/public/game')->assertOk();
        }
    }
}
