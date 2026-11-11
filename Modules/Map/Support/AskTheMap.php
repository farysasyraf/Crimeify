<?php

namespace Modules\Map\Support;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Map\Entities\PoliceDistrict;
use Psr\Http\Client\ClientInterface;

// "Ask the map", over the crime map (MapAskController, map-ask.js): what a visitor would like the map to show, like
// "Break-ins in Selangor in 2019, in 3D" or "Where did motorcycle theft fall most?", turned by Claude into a view the
// map goes to (MapView): a year, a region, what the states are shaded by, what the pins show, 3D, police districts to
// point out, and whether to play the years. Claude answers in JSON to a schema of only what the map can show, looking
// districts up with Ask Crimeify's tools first if it needs to, and every part is checked again here, so the answer is a
// view, not a claim. Its caption is kept only if each figure in it is one the tools found.
final class AskTheMap
{
    use TalksToClaude;

    /**
     * Police districts pointed out at most.
     */
    private const MostDistricts = 5;

    /**
     * @param  ?ClientInterface  $transporter  what sends the requests, in place of the SDK's own, as in tests
     */
    public function __construct(
        private readonly AskCrimeifyTools $tools,
        private readonly ?ClientInterface $transporter = null,
    ) {}

    /**
     * The view to go to from the one shown now: only the parts to change, police districts to point out by their
     * pins' keys ("MY-10|Kajang"), whether to play the years, and a caption, if it can be trusted.
     *
     * @param  array<string, mixed>  $now
     * @return array{view: array{year: ?int, region: ?string, shade: ?string, pins: ?string, raised: ?bool}, districts: list<string>, play: bool, caption: ?string}
     *
     * @throws AskProblem
     */
    public function answer(string $question, array $now): array
    {
        $question = Str::squish($question);
        $now = MapView::clean($now, complete: true);
        $key = 'ask-the-map:'.sha1(implode('|', [Str::lower($question), json_encode($now), config('map.ask.model'), MapView::version()]));

        return Cache::get($key) ?? tap($this->ask($question, $now), fn (array $answer) => Cache::put($key, $answer, now()->addMinutes(config('map.ask.cache_minutes'))));
    }

    /**
     * @param  array<string, mixed>  $now
     * @return array{view: array{year: ?int, region: ?string, shade: ?string, pins: ?string, raised: ?bool}, districts: list<string>, play: bool, caption: ?string}
     *
     * @throws AskProblem
     */
    private function ask(string $question, array $now): array
    {
        $client = $this->client();
        $messages = [['role' => 'user', 'content' => 'The map shows now: '.json_encode($now)."\n\nThe visitor asks: {$question}"]];
        $rounds = (int) config('map.ask.rounds');
        // What the lookups found, which the caption's figures must come from.
        $found = [];

        for ($round = 1; $round <= $rounds; $round++) {
            // The last time, no more looking up: a view from what it has.
            $response = $this->request($client, $this->instructions(), $messages, AskCrimeifyTools::definitions(), finish: $round === $rounds, schema: self::schema());

            if ($response->stopReason !== 'tool_use') {
                return $this->view($response, $found);
            }

            // Its turn as it came, thinking included, then what each lookup found.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $results = $this->results($response);
            foreach ($results as $result) {
                $found[] = ($result['isError'] ?? false) ? [] : json_decode($result['content'], true);
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new AskProblem(__('That needed more looking up than one answer allows. Try something simpler.'), 422);
    }

    /**
     * The view in Claude's answer, checked part by part.
     *
     * @param  list<mixed>  $found
     * @return array{view: array{year: ?int, region: ?string, shade: ?string, pins: ?string, raised: ?bool}, districts: list<string>, play: bool, caption: ?string}
     *
     * @throws AskProblem
     */
    private function view(BetaMessage $response, array $found): array
    {
        $answer = json_decode($this->textOf($response), true);

        if (! is_array($answer)) {
            throw new AskProblem(__("The map's AI gave an answer the map can't use. Try asking another way."), 502);
        }

        $caption = is_string($answer['caption'] ?? null) ? Str::limit(Str::squish($answer['caption']), 400) : '';

        if (($answer['understood'] ?? false) !== true) {
            throw new AskProblem($caption !== '' ? $caption : __("The map can't show that. Ask for a year, a state, a kind of crime or police districts."), 422);
        }

        $view = MapView::clean($answer);

        // Each police district by its pin's key, as the tools named it; any there isn't left out.
        $districts = PoliceDistrict::query()->get();
        $keys = collect(is_array($answer['districts'] ?? null) ? $answer['districts'] : [])
            ->filter(fn ($each) => is_array($each) && is_string($each['region'] ?? null) && is_string($each['name'] ?? null))
            ->map(fn (array $each) => $districts->first(fn (PoliceDistrict $district) => $district->Region === $each['region'] && strcasecmp($district->Name, trim($each['name'])) === 0))
            ->filter()
            ->map(fn (PoliceDistrict $district) => $district->Region.'|'.$district->Name)
            ->unique()->take(self::MostDistricts)->values()->all();

        return [
            'view' => $view,
            'districts' => $keys,
            'play' => ($answer['play'] ?? false) === true,
            // A caption with a figure the tools didn't give isn't shown; the view still is.
            'caption' => $caption !== '' && MapView::backedBy($caption, [$found, MapView::years()]) ? $caption : null,
        ];
    }

    /**
     * What each lookup in Claude's turn found, as JSON, or why it couldn't, for Claude to try again.
     *
     * @return list<array{type: string, toolUseID: string, content: string, isError?: bool}>
     */
    private function results(BetaMessage $response): array
    {
        $results = [];

        foreach ($response->content as $block) {
            if (! $block instanceof BetaToolUseBlock) {
                continue;
            }

            try {
                $found = json_encode($this->tools->run($block->name, $block->input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $found];
            } catch (InvalidArgumentException $problem) {
                $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $problem->getMessage(), 'isError' => true];
            }
        }

        return $results;
    }

    /**
     * The answer's JSON schema: every part of a view, null to leave it as it is.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $codes = array_keys(MapView::regions());
        $or = fn (array $schema) => ['anyOf' => [$schema, ['type' => 'null']]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['understood', 'year', 'region', 'shade', 'pins', 'raised', 'districts', 'play', 'caption'],
            'properties' => [
                'understood' => ['type' => 'boolean', 'description' => 'False if the map cannot show it from Crimeify\'s figures.'],
                'year' => $or(['type' => 'integer']),
                'region' => $or(['type' => 'string', 'enum' => $codes]),
                'shade' => $or(['type' => 'string', 'enum' => array_keys(CrimeRates::measures())]),
                'pins' => $or(['type' => 'string', 'enum' => array_map('strval', array_keys(MapView::crimes()))]),
                'raised' => $or(['type' => 'boolean']),
                'districts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['region', 'name'],
                        'properties' => ['region' => ['type' => 'string', 'enum' => array_values(array_diff($codes, ['Malaysia']))], 'name' => ['type' => 'string']],
                    ],
                ],
                'play' => ['type' => 'boolean'],
                'caption' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * What Claude is told: what the map can show and how to choose it. The same for every request, but for the years,
     * which change only with the figures, so it's cached.
     */
    private function instructions(): string
    {
        $years = MapView::years();
        $districtYears = StateCrime::districtYears();
        $range = $years === [] ? 'none yet' : min($years).' to '.max($years);
        $byDistrict = $districtYears === [] ? 'none' : implode(', ', $districtYears);
        $regions = collect(MapView::regions())->map(fn (string $name, string $code) => "{$code} ({$name})")->implode(', ');
        $crimes = collect(MapView::crimes())->except('')->map(fn (string $label, string $key) => "{$key} ({$label})")->implode(', ');

        return <<<TEXT
            You are "Ask the map", on the crime map in Crimeify, a web app about crimes reported to the police in Malaysia. A visitor says what they'd like the map to show, and you choose the view that shows it. The map draws it.

            What the map can show:
            - year: one of the years with figures, {$range}. Police districts have figures for {$byDistrict}; other years are by state only, with no police district figures.
            - region: a state or federal territory, zoomed to and described in the panel, or "Malaysia" for the whole country. The regions: {$regions}.
            - shade: what each state is shaded by, its crime per 100,000 people: "all" (violent and property crime), "assault" (violent crime) or "property" (property crime).
            - pins: what each police district's pin shows: "" for only where it is, or a bubble sized by its count of a crime: {$crimes}.
            - raised: true stands each state up in 3D by its crime per 100,000 people; false is flat.
            - districts: police districts to point out, at most 5, each with its region code and its name exactly as your tools give them.
            - play: true plays the years one after another from the first, to watch them change.

            How to answer:
            - The view the map shows now comes with the request. Give null for anything the visitor didn't ask to change, so "now 2019" or "and Johor?" changes only that.
            - To point out police districts, look them up with your tools first: find_places for a name, rank_districts for the most, the fewest, or the biggest rise or fall. Then choose the year, region and pins the figures are for, so the map shows what you found.
            - caption: one or two short sentences saying what the map now shows and, if you looked figures up, what they say. Every figure in it comes from a tool; never estimate one. Plain text, no Markdown. In the language of the request, Bahasa Melayu or English.
            - Don't call a place safe or dangerous, or rank places by how safe they are. A police district's counts aren't per person.
            - understood: false if it isn't something the map can show from Crimeify's figures, with a caption saying what it can show.
            TEXT;
    }
}
