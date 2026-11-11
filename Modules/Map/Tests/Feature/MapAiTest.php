<?php

namespace Modules\Map\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Map\Support\AskCrimeifyTools;
use Modules\Map\Support\AskProblem;
use Modules\Map\Support\AskTheMap;
use Modules\Map\Support\ExplainView;
use Modules\Map\Support\MapView;
use Tests\TestCase;

class MapAiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As AskCrimeifyTest has them: Johor's Batu Pahat (break-ins 30, then 45) and Muar (20, then 10), and Malaysia,
     * 6,000 then 6,100 crimes.
     */
    private const Figures = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,Batu Pahat,assault,causing_injury,2022-01-01,20',
        'Johor,Batu Pahat,property,break_in,2022-01-01,30',
        'Johor,Batu Pahat,property,theft_vehicle_motorcycle,2022-01-01,50',
        'Johor,Batu Pahat,assault,causing_injury,2023-01-01,15',
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
     * Claude, as fake, for both of the map's AI.
     *
     * @param  list<array<string, mixed>|int>  $answers  each a message, or an HTTP status to fail with
     */
    private function claude(array $answers): FakeClaude
    {
        $fake = new FakeClaude(array_map(fn (array|int $answer) => is_int($answer)
            ? new Response($answer, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'No.']]))
            : new Response(200, ['Content-Type' => 'application/json'], json_encode($answer)), $answers));
        $this->app->instance(AskTheMap::class, new AskTheMap(new AskCrimeifyTools, $fake));
        $this->app->instance(ExplainView::class, new ExplainView(new AskCrimeifyTools, $fake));

        return $fake;
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function message(string $stop, array $content): array
    {
        return [
            'id' => 'msg_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null, 'stop_details' => null,
            'container' => null, 'context_management' => null, 'diagnostics' => null,
            'usage' => ['input_tokens' => 900, 'output_tokens' => 60, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 800],
        ];
    }

    /**
     * A message whose text is this view, as JSON, as Claude answers Ask the map.
     *
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private static function asView(array $view): array
    {
        return self::message('end_turn', [['type' => 'text', 'text' => json_encode($view + [
            'understood' => true, 'year' => null, 'region' => null, 'shade' => null, 'pins' => null, 'raised' => null, 'districts' => [], 'play' => false, 'caption' => '',
        ])]]);
    }

    public function test_a_view_has_only_what_the_map_can_show(): void
    {
        $this->assertSame(
            ['year' => 2023, 'region' => 'MY-01', 'shade' => 'property', 'pins' => 'break_in', 'raised' => true],
            MapView::clean(['year' => '2023', 'region' => 'MY-01', 'shade' => 'property', 'pins' => 'break_in', 'raised' => true, 'zoom' => 9]),
        );
        $this->assertSame(
            ['year' => null, 'region' => null, 'shade' => null, 'pins' => null, 'raised' => null],
            MapView::clean(['year' => 1999, 'region' => 'MY-99', 'shade' => 'safety', 'pins' => 'jaywalking', 'raised' => 'yes']),
        );
        // Complete, the map's own defaults: its latest year, all of Malaysia, both categories, plain pins, flat.
        $this->assertSame(['year' => 2023, 'region' => 'Malaysia', 'shade' => 'all', 'pins' => '', 'raised' => false], MapView::clean([], complete: true));
    }

    public function test_only_figures_it_was_given_get_through(): void
    {
        $given = ['crimes' => 20, 'crimes_in_from_year' => 1234, 'change_percent' => -50.0, 'rate' => 512.34, 'in_3d' => false, 'place' => 'MY-07'];

        $this->assertTrue(MapView::backedBy('Break-ins fell 50%, from 1,234 to 20, in the 3 districts.', $given));
        $this->assertTrue(MapView::backedBy('About 512.3 per 100,000 people, or 512.', $given));
        $this->assertFalse(MapView::backedBy('Break-ins fell 55%.', $given));
        $this->assertFalse(MapView::backedBy('There were 21 break-ins.', $given));
        // Not a number from a name: in_3d, or a region's code.
        $this->assertFalse(MapView::backedBy('Crime rose 3%, or 7%.', $given));
    }

    public function test_ask_the_map_looks_districts_up_then_gives_a_view_checked_part_by_part(): void
    {
        $fake = $this->claude([
            self::message('tool_use', [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'rank_districts', 'input' => ['region' => 'MY-01', 'crime' => 'break_in', 'year' => 2023, 'from_year' => 2022, 'order' => 'biggest_fall', 'limit' => 2]],
            ]),
            self::asView([
                'year' => 2023, 'region' => 'MY-01', 'pins' => 'break_in', 'raised' => true,
                'districts' => [['region' => 'MY-01', 'name' => 'muar'], ['region' => 'MY-01', 'name' => 'Nowhere']],
                'caption' => 'Break-ins fell most in Muar, from 20 in 2022 to 10 in 2023, down 50%.',
            ]),
        ]);

        $answer = app(AskTheMap::class)->answer('Where in Johor did break-ins fall most?', ['year' => 2022, 'region' => 'Malaysia']);

        // Only what it changes; the districts by their pins' keys, any there isn't left out.
        $this->assertSame([
            'view' => ['year' => 2023, 'region' => 'MY-01', 'shade' => null, 'pins' => 'break_in', 'raised' => true],
            'districts' => ['MY-01|Muar'],
            'play' => false,
            'caption' => 'Break-ins fell most in Muar, from 20 in 2022 to 10 in 2023, down 50%.',
        ], $answer);

        // The view shown went with the question, and the answer had to be a view, after its lookups.
        [$first] = $fake->sent;
        $this->assertStringContainsString('The map shows now: {"year":2022,"region":"Malaysia","shade":"all","pins":"","raised":false}', $first['body']['messages'][0]['content']);
        $this->assertSame(['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => AskTheMap::schema()]], $first['body']['output_config']);
        $this->assertSame(['find_places', 'district_figures', 'state_figures', 'rank_districts'], array_column($first['body']['tools'], 'name'));
        $this->assertContains('MY-01', $first['body']['output_config']['format']['schema']['properties']['region']['anyOf'][0]['enum']);

        // Asked the same again, it isn't paid for twice.
        app(AskTheMap::class)->answer('where in johor did break-ins  fall most?', ['year' => 2022, 'region' => 'Malaysia']);
        $this->assertCount(2, $fake->sent);
    }

    public function test_a_caption_with_a_figure_it_didnt_look_up_is_left_out(): void
    {
        $this->claude([self::asView(['pins' => 'theft_vehicle_motorcycle', 'caption' => 'Motorcycle theft is down 37% this year.'])]);

        $answer = app(AskTheMap::class)->answer('Motorcycle theft', []);

        $this->assertSame('theft_vehicle_motorcycle', $answer['view']['pins']);
        $this->assertNull($answer['caption']);
    }

    public function test_what_the_map_cant_show_is_said(): void
    {
        $this->claude([self::asView(['understood' => false, 'caption' => 'The map shows crime by year, state and type, not the weather.'])]);

        $this->expectExceptionObject(new AskProblem('The map shows crime by year, state and type, not the weather.', 422));
        app(AskTheMap::class)->answer('Will it rain in Johor?', []);
    }

    public function test_anyone_may_ask_the_map_and_visitors_not_logged_in_fewer_times(): void
    {
        config(['map.ask.guest_per_minute' => 1]);
        $this->claude([self::asView(['region' => 'MY-01', 'pins' => 'break_in']), self::asView(['region' => 'MY-02'])]);

        $this->postJson('/public/map/ask', ['question' => 'Break-ins in Johor', 'now' => ['year' => 2023]])
            ->assertOk()
            ->assertJsonPath('view.region', 'MY-01')
            ->assertJsonPath('view.pins', 'break_in')
            ->assertJsonPath('caption', null);
        $this->postJson('/public/map/ask', ['question' => 'And Kedah?'])->assertTooManyRequests();

        // Logged in, a user's own limits, as for Ask Crimeify.
        $this->signIn();
        $this->postJson('/public/map/ask', ['question' => 'And Kedah?'])->assertOk()->assertJsonPath('view.region', 'MY-02');
    }

    public function test_all_visitors_not_logged_in_have_a_ceiling_a_day_together(): void
    {
        config(['map.ask.guests_per_day' => 1]);
        $this->claude([self::asView(['region' => 'MY-01'])]);

        $this->postJson('/public/map/ask', ['question' => 'Johor'], ['REMOTE_ADDR' => '203.0.113.1'])->assertOk();
        $this->postJson('/public/map/ask', ['question' => 'Kedah'], ['REMOTE_ADDR' => '203.0.113.2'])->assertTooManyRequests();
    }

    public function test_the_facts_of_a_view_are_worked_out_here(): void
    {
        $facts = (new ExplainView(new AskCrimeifyTools))->facts(MapView::clean(['year' => 2023, 'pins' => 'break_in'], complete: true));

        $this->assertSame(['year' => 2023, 'place' => 'Malaysia', 'states_shaded_by' => 'Violent and property crime per 100,000 people', 'pins_show' => 'Break-in', 'in_3d' => false], $facts['view']);
        $this->assertSame([2023, 6100], [$facts['place']['this_year']['year'], $facts['place']['this_year']['all']]);
        $this->assertSame(6000, $facts['place']['year_before']['all']);

        // The most break-ins, and the biggest changes among districts with at least 20 the year before.
        $districts = $facts['police_districts'];
        $this->assertSame(['Batu Pahat', 'Muar'], array_column($districts['most'], 'district'));
        $this->assertSame([['district' => 'Muar', 'state' => 'Johor', 'crimes' => 10, 'crimes_in_from_year' => 20, 'change_percent' => -50.0]], $districts['biggest_falls']);
        $this->assertSame(['Batu Pahat'], array_column($districts['biggest_rises'], 'district'));
        $this->assertStringContainsString('Not per person', $districts['note']);
    }

    public function test_a_description_with_a_figure_not_in_the_facts_is_written_again(): void
    {
        $fake = $this->claude([
            self::message('end_turn', [['type' => 'text', 'text' => 'In 2023, crime in Malaysia rose 3%.']]),
            self::message('end_turn', [['type' => 'text', 'text' => 'In 2023, Malaysia had 6,100 crimes, up from 6,000 in 2022.']]),
        ]);

        $this->getJson('/public/map/explain?year=2023&region=Malaysia&shade=all&pins=&raised=0')
            ->assertOk()
            ->assertExactJson([
                'view' => ['year' => 2023, 'region' => 'Malaysia', 'shade' => 'all', 'pins' => '', 'raised' => false],
                'text' => 'In 2023, Malaysia had 6,100 crimes, up from 6,000 in 2022.',
            ]);

        // Told why, the second time; without tools, as the facts are given.
        $this->assertStringContainsString("A figure in that isn't in the facts.", $fake->sent[1]['body']['messages'][2]['content']);
        $this->assertArrayNotHasKey('tools', $fake->sent[0]['body']);
        $this->assertSame(6100, json_decode($fake->sent[0]['body']['messages'][0]['content'], true)['place']['this_year']['all']);

        // The same view again is kept, for anyone.
        $this->getJson('/public/map/explain?year=2023&region=Malaysia&shade=all&pins=&raised=0')->assertOk();
        $this->assertCount(2, $fake->sent);
    }

    public function test_a_view_it_cant_describe_twice_has_no_description(): void
    {
        $this->claude([
            self::message('end_turn', [['type' => 'text', 'text' => 'Crime rose 3%.']]),
            self::message('end_turn', [['type' => 'text', 'text' => 'Crime rose 4%.']]),
        ]);

        $this->getJson('/public/map/explain?year=2023')
            ->assertStatus(502)
            ->assertExactJson(['problem' => "This view couldn't be described just now. Try another, or again later."]);
    }

    public function test_the_map_offers_its_ai_to_everyone_but_only_with_a_key(): void
    {
        $this->get('/public/map')->assertOk()
            ->assertSee('data-explain="'.url('/public/map/explain').'" data-ask="'.url('/public/map/ask').'"', false)
            ->assertSee('Where did break-ins fall most?')
            ->assertSee('modules/map/map-ask.js', false);

        $this->get('/public/map?lang=ms')->assertOk()->assertSee('Apakah yang saya lihat?')->assertSee('data-ask="'.url('/public/map/ask?lang=ms').'"', false);

        config(['map.ask.key' => null]);
        $this->get('/public/map')->assertOk()->assertDontSee('data-map-ai', false)->assertDontSee('map-ask.js', false);
        Cache::flush();
    }
}
