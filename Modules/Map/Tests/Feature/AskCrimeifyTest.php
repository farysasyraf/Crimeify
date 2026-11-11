<?php

namespace Modules\Map\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\Map\Support\AskCrimeify;
use Modules\Map\Support\AskCrimeifyTools;
use Modules\Map\Support\AskProblem;
use Tests\TestCase;

class AskCrimeifyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * data.gov.my's file, cut down to 2022 and 2023, as AreaProfileTest has it: Johor's Batu Pahat (100, then 90
     * crimes) and Muar (200, then 160), and Malaysia, 6,000 then 6,100.
     */
    private const Figures = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,Batu Pahat,assault,murder,2022-01-01,2',
        'Johor,Batu Pahat,assault,causing_injury,2022-01-01,18',
        'Johor,Batu Pahat,property,break_in,2022-01-01,30',
        'Johor,Batu Pahat,property,theft_vehicle_motorcycle,2022-01-01,50',
        'Johor,Batu Pahat,assault,murder,2023-01-01,1',
        'Johor,Batu Pahat,assault,causing_injury,2023-01-01,14',
        'Johor,Batu Pahat,property,break_in,2023-01-01,45',
        'Johor,Batu Pahat,property,theft_vehicle_motorcycle,2023-01-01,30',
        'Johor,Muar,assault,causing_injury,2022-01-01,30',
        'Johor,Muar,property,break_in,2022-01-01,20',
        'Johor,Muar,property,theft_vehicle_motorcycle,2022-01-01,150',
        'Johor,Muar,assault,causing_injury,2023-01-01,30',
        'Johor,Muar,property,break_in,2023-01-01,10',
        'Johor,Muar,property,theft_vehicle_motorcycle,2023-01-01,120',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", self::Figures)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        $this->artisan('map:import-crime', ['file' => $path])->assertSuccessful();

        config(['map.ask.key' => 'test-key', 'map.ask.model' => 'claude-opus-5-5', 'map.ask.effort' => 'low']);
    }

    /**
     * Claude, as fake: each request kept, and answered in turn with what it's given.
     *
     * @param  list<array<string, mixed>|int>  $answers  each a message, or an HTTP status to fail with
     */
    private function claude(array $answers): FakeClaude
    {
        $fake = new FakeClaude(array_map(fn (array|int $answer) => is_int($answer)
            ? new Response($answer, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'No.']]))
            : new Response(200, ['Content-Type' => 'application/json'], json_encode($answer)), $answers));
        $this->app->instance(AskCrimeify::class, new AskCrimeify(new AskCrimeifyTools, $fake));

        return $fake;
    }

    /**
     * A message from Claude that stopped for a reason, with its content blocks.
     *
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function message(string $stop, array $content): array
    {
        return [
            'id' => 'msg_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null, 'stop_details' => null,
            'container' => null, 'context_management' => null, 'diagnostics' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 80, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 1100],
        ];
    }

    private function tools(): AskCrimeifyTools
    {
        return new AskCrimeifyTools;
    }

    public function test_it_finds_states_and_police_districts_by_part_of_their_name(): void
    {
        $this->assertSame(
            [['district' => 'Batu Pahat', 'state' => 'Johor', 'region' => 'MY-01', 'profile' => url('/public/areas/johor/batu-pahat')]],
            $this->tools()->run('find_places', ['query' => 'batu pa'])['police_districts'],
        );
        $this->assertSame([['region' => 'MY-01', 'name' => 'Johor']], $this->tools()->run('find_places', ['query' => 'JOHOR'])['states']);
    }

    public function test_a_police_districts_figures_are_its_profiles(): void
    {
        $figures = $this->tools()->run('district_figures', ['region' => 'MY-01', 'district' => 'batu pahat']);

        $this->assertSame([
            ['year' => 2022, 'violent' => 20, 'property' => 80, 'all' => 100],
            ['year' => 2023, 'violent' => 15, 'property' => 75, 'all' => 90],
        ], $figures['years']);
        $this->assertSame(-10.0, $figures['change_from_year_before_percent']);
        $this->assertSame(['from' => 2022, 'to' => 2023, 'here' => -10.0, 'Johor' => -16.7, 'Malaysia' => 1.7], $figures['change_since_first_year_percent']);
        $this->assertSame(['type' => 'Break-in', 'crimes' => 45, 'share_percent' => 50.0, 'share_across_Johor_percent' => 22.0], $figures['crime_types_latest_year'][0]);
        $this->assertSame(url('/public/areas/johor/batu-pahat'), $figures['profile']);
        $this->assertStringContainsString('Not per person', $figures['note']);

        $this->expectException(InvalidArgumentException::class);
        $this->tools()->run('district_figures', ['region' => 'MY-10', 'district' => 'Batu Pahat']);
    }

    public function test_a_states_figures_each_year_with_the_years_by_state_only(): void
    {
        $johor = $this->tools()->run('state_figures', ['region' => 'MY-01']);

        $this->assertSame('Johor', $johor['place']);
        $this->assertSame(['year' => 2022, 'violent' => 50, 'property' => 250, 'all' => 300], array_slice($johor['years'][0], 0, 4));
        $this->assertSame(['year' => 2023, 'violent' => 45, 'property' => 205, 'all' => 250], array_slice($johor['years'][1], 0, 4));
        $this->assertArrayHasKey('years_by_state_only', $johor);

        $this->assertSame(6100, collect($this->tools()->run('state_figures', ['region' => 'Malaysia'])['years'])->firstWhere('year', 2023)['all']);
    }

    public function test_police_districts_rank_by_a_crime_or_its_change(): void
    {
        $most = $this->tools()->run('rank_districts', ['region' => 'MY-01', 'crime' => 'property', 'year' => 2023, 'from_year' => null, 'order' => 'most', 'limit' => null]);
        $this->assertSame([['Muar', 130], ['Batu Pahat', 75]], array_map(fn (array $district) => [$district['district'], $district['crimes']], $most['ranked']));
        $this->assertSame(2, $most['districts_compared']);

        // Break-ins rose 50% in Batu Pahat and fell 50% in Muar.
        $rise = $this->tools()->run('rank_districts', ['region' => null, 'crime' => 'break_in', 'year' => null, 'from_year' => 2022, 'order' => 'biggest_rise', 'limit' => 1]);
        $this->assertSame(['rank' => 1, 'district' => 'Batu Pahat', 'state' => 'Johor', 'region' => 'MY-01', 'profile' => url('/public/areas/johor/batu-pahat'), 'crimes' => 45, 'crimes_in_from_year' => 30, 'change_percent' => 50.0], $rise['ranked'][0]);
        $this->assertSame(2023, $rise['year']);
    }

    public function test_a_tool_says_what_it_cant_use(): void
    {
        foreach ([
            ['year' => 2019, 'from_year' => null, 'order' => 'most'],
            ['year' => 2023, 'from_year' => null, 'order' => 'biggest_fall'],
        ] as $asked) {
            try {
                $this->tools()->run('rank_districts', ['region' => null, 'crime' => 'all', 'limit' => null] + $asked);
                $this->fail('It ranked what it shouldn\'t have.');
            } catch (InvalidArgumentException $problem) {
                $this->assertNotSame('', $problem->getMessage());
            }
        }
    }

    public function test_claude_looks_the_figures_up_then_answers_and_the_answer_is_kept(): void
    {
        $fake = $this->claude([
            self::message('tool_use', [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'rank_districts', 'input' => ['region' => 'MY-01', 'crime' => 'break_in', 'year' => 2023, 'from_year' => 2022, 'order' => 'biggest_fall', 'limit' => 3]],
            ]),
            self::message('end_turn', [['type' => 'text', 'text' => 'Break-ins fell most in [Muar](http://localhost/public/areas/johor/muar): 20 in 2022, 10 in 2023.']]),
        ]);

        $this->assertSame(
            ['answer' => 'Break-ins fell most in [Muar](http://localhost/public/areas/johor/muar): 20 in 2022, 10 in 2023.'],
            app(AskCrimeify::class)->answer('Which district in Johor had the biggest fall in break-ins?'),
        );

        // Opus 5.5, quick, with the four strict tools, cached, and with a refusal tried again on another model.
        [$first, $second] = $fake->sent;
        $this->assertSame(['claude-opus-5-5', 'default', ['effort' => 'low'], ['type' => 'auto'], ['type' => 'ephemeral']], [
            $first['body']['model'], $first['body']['fallbacks'], $first['body']['output_config'], $first['body']['tool_choice'], $first['body']['cache_control'],
        ]);
        $this->assertSame(['find_places', 'district_figures', 'state_figures', 'rank_districts'], array_column($first['body']['tools'], 'name'));
        $this->assertSame([true, true, true, true], array_column($first['body']['tools'], 'strict'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', implode(',', $first['headers']['anthropic-beta'] ?? []));
        $this->assertSame('Bearer test-key', $first['headers']['authorization'][0] ?? null);

        // What it found went back to it, after its own turn.
        $this->assertSame('tool_use', $second['body']['messages'][1]['content'][0]['type']);
        $found = $second['body']['messages'][2]['content'][0];
        $this->assertSame(['tool_result', 'toolu_1'], [$found['type'], $found['tool_use_id']]);
        $this->assertSame('Muar', json_decode($found['content'], true)['ranked'][0]['district']);

        // The same question again, in any spacing or case, isn't asked again.
        app(AskCrimeify::class)->answer('which district in johor had  the biggest fall in break-ins?');
        $this->assertCount(2, $fake->sent);
    }

    public function test_a_lookup_it_cant_do_goes_back_as_an_error_and_the_last_round_must_answer(): void
    {
        config(['map.ask.rounds' => 2]);
        $fake = $this->claude([
            self::message('tool_use', [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'district_figures', 'input' => ['region' => 'MY-01', 'district' => 'Nowhere']]]),
            self::message('end_turn', [['type' => 'text', 'text' => "There's no police district called Nowhere."]]),
        ]);

        app(AskCrimeify::class)->answer('How much crime is there in Nowhere?');

        $result = $fake->sent[1]['body']['messages'][2]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('find_places', $result['content']);
        $this->assertSame(['type' => 'none'], $fake->sent[1]['body']['tool_choice']);
    }

    public function test_a_refusal_or_a_failed_request_is_said_in_words(): void
    {
        $this->claude([self::message('refusal', [])]);
        try {
            app(AskCrimeify::class)->answer('Something it won\'t answer');
            $this->fail('It answered.');
        } catch (AskProblem $problem) {
            $this->assertSame([422, "Ask Crimeify can't answer that. Ask about Crimeify's crime figures."], [$problem->status, $problem->getMessage()]);
        }

        $this->claude([400]);
        $this->expectExceptionObject(new AskProblem("Ask Crimeify couldn't answer just now. Try again in a moment.", 502));
        app(AskCrimeify::class)->answer('Anything at all?');
    }

    public function test_the_palette_asks_for_anyone_logged_in_a_few_times_a_minute(): void
    {
        $this->postJson('/palette/ask', ['question' => 'How much crime is there in Muar?'])->assertUnauthorized();

        $this->signIn();
        config(['map.ask.per_minute' => 1]);
        $this->claude([self::message('end_turn', [['type' => 'text', 'text' => 'Muar had 160 crimes in 2023.']])]);

        $this->postJson('/palette/ask', ['question' => 'How much crime is there in Muar?'])->assertOk()->assertExactJson(['answer' => 'Muar had 160 crimes in 2023.']);
        $this->postJson('/palette/ask', ['question' => 'And in Batu Pahat?'])->assertTooManyRequests();
    }

    public function test_the_palette_says_why_it_cant_ask(): void
    {
        $this->signIn();

        $this->postJson('/palette/ask', ['question' => 'x'])->assertUnprocessable();

        config(['map.ask.key' => null]);
        Cache::flush();
        $this->postJson('/palette/ask', ['question' => 'How much crime is there in Muar?'])
            ->assertServiceUnavailable()->assertExactJson(['problem' => "Ask Crimeify isn't set up: it needs ANTHROPIC_API_KEY."]);
    }

    public function test_the_palette_offers_it_only_with_a_key(): void
    {
        $this->signIn();
        $this->get('/profile')->assertOk()->assertSee('data-ask="'.route('palette.ask').'" data-token="', false);

        config(['map.ask.key' => null]);
        $this->get('/profile')->assertOk()->assertDontSee('data-ask=', false);
    }
}
