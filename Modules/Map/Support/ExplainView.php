<?php

namespace Modules\Map\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;

// "What am I looking at?", over the crime map (MapAskController, map-ask.js): two or three sentences about the view
// shown (MapView), like "In 2021, property crime fell in every state; Penang fell most, 38%." The facts are worked out
// here, from Ask Crimeify's tools and the map's rates, and Claude only puts them into words: every figure in what it
// writes must be one of them, or it's asked once more, and then there's no description rather than a wrong one. Each
// view's description is kept until the figures change, so most views cost nothing after the first.
final class ExplainView
{
    use TalksToClaude;

    /**
     * Police districts with fewer crimes than this the year before aren't counted among the biggest changes: a few
     * crimes more or less would be a big part of them.
     */
    private const FewestToCompare = 20;

    /**
     * @param  ?ClientInterface  $transporter  what sends the requests, in place of the SDK's own, as in tests
     */
    public function __construct(
        private readonly AskCrimeifyTools $tools,
        private readonly ?ClientInterface $transporter = null,
    ) {}

    /**
     * The view, as described, and what was said about it.
     *
     * @param  array<string, mixed>  $view
     * @return array{view: array{year: ?int, region: ?string, shade: ?string, pins: ?string, raised: ?bool}, text: string}
     *
     * @throws AskProblem
     */
    public function explain(array $view): array
    {
        $view = MapView::clean($view, complete: true);
        $key = 'explain-view:'.sha1(implode('|', [json_encode($view), app()->getLocale(), config('map.ask.model'), MapView::version()]));

        return Cache::get($key) ?? tap(['view' => $view, 'text' => $this->describe($view)], fn (array $said) => Cache::put($key, $said, now()->addHours(config('map.ask.explain_hours'))));
    }

    /**
     * What the view shows, as figures: the year, the place chosen and how it changed, how the states compare, and,
     * with bubbles, the police districts with the most of that crime and the biggest changes.
     *
     * @param  array{year: ?int, region: ?string, shade: ?string, pins: ?string, raised: ?bool}  $view
     * @return array<string, mixed>
     */
    public function facts(array $view): array
    {
        $years = MapView::years();
        $year = $view['year'];
        $previous = in_array($year - 1, $years, true) ? $year - 1 : null;
        $first = $years[0] ?? null;
        $measure = $view['shade'] ?? 'all';
        $measures = CrimeRates::measures();

        $place = $this->tools->run('state_figures', ['region' => $view['region']]);
        $byYear = collect($place['years'])->keyBy('year');
        $trim = fn (?array $figures) => $figures === null ? null : array_diff_key($figures, ['people' => true]);

        $facts = [
            'view' => [
                'year' => $year,
                'place' => $place['place'],
                'states_shaded_by' => $measures[$measure].' '.__('per 100,000 people'),
                'pins_show' => MapView::crimes()[$view['pins'] ?? ''],
                'in_3d' => (bool) $view['raised'],
            ],
            'place' => [
                'name' => $place['place'],
                'this_year' => $trim($byYear[$year] ?? null),
                'year_before' => $previous === null ? null : $trim($byYear[$previous] ?? null),
                'first_year' => $first === null || $first === $year ? null : $trim($byYear[$first] ?? null),
            ],
        ];

        // All of Malaysia: the states with the most and the least crime per person, and the biggest changes in it.
        $rates = CrimeRates::all()['years'];
        if ($view['region'] === 'Malaysia' && ! empty($rates[$year]['regions'])) {
            $now = collect($rates[$year]['regions'])->map(fn (array $region) => round($region['rates'][$measure], 1))->sortDesc();
            $name = fn (string $code) => config("map.states.{$code}.name");
            $facts['states_per_100000_people'] = [
                'measure' => $measures[$measure],
                'most' => $now->take(3)->map(fn (float $rate, string $code) => ['state' => $name($code), 'rate' => $rate])->values()->all(),
                'least' => $now->reverse()->take(2)->map(fn (float $rate, string $code) => ['state' => $name($code), 'rate' => $rate])->values()->all(),
            ];

            if ($previous !== null && ! empty($rates[$previous]['regions'])) {
                $changes = $now->map(function (float $rate, string $code) use ($rates, $previous, $measure) {
                    $before = $rates[$previous]['regions'][$code]['rates'][$measure] ?? null;

                    return $before ? round(($rate - $before) / $before * 100, 1) : null;
                })->filter(fn (?float $change) => $change !== null)->sort();
                $facts['states_per_100000_people'] += [
                    'states_fallen_since_year_before' => $changes->filter(fn (float $change) => $change < 0)->count(),
                    'states_risen_since_year_before' => $changes->filter(fn (float $change) => $change > 0)->count(),
                    'biggest_falls_percent' => $changes->filter(fn (float $change) => $change < 0)->take(2)->map(fn (float $change, string $code) => ['state' => $name($code), 'change' => $change])->values()->all(),
                    'biggest_rises_percent' => $changes->filter(fn (float $change) => $change > 0)->reverse()->take(2)->map(fn (float $change, string $code) => ['state' => $name($code), 'change' => $change])->values()->all(),
                ];
            }
        }

        // Bubbles of a crime: the police districts with the most of it, and the biggest changes from the year before.
        if (($view['pins'] ?? '') !== '' && in_array($year, StateCrime::districtYears(), true)) {
            $within = $view['region'] === 'Malaysia' ? null : $view['region'];
            $brief = fn (array $district) => array_intersect_key($district, array_flip(['district', 'state', 'crimes', 'crimes_in_from_year', 'change_percent']));

            try {
                $most = $this->tools->run('rank_districts', ['year' => $year, 'crime' => $view['pins'], 'region' => $within, 'order' => 'most', 'limit' => 3]);
                $districts = ['crime' => MapView::crimes()[$view['pins']], 'compared' => $most['districts_compared'], 'most' => array_map($brief, $most['ranked'])];

                if ($previous !== null && in_array($previous, StateCrime::districtYears(), true)) {
                    foreach (['biggest_fall' => 'biggest_falls', 'biggest_rise' => 'biggest_rises'] as $order => $called) {
                        $ranked = $this->tools->run('rank_districts', ['year' => $year, 'from_year' => $previous, 'crime' => $view['pins'], 'region' => $within, 'order' => $order, 'limit' => 15]);
                        $districts[$called] = collect($ranked['ranked'])
                            ->filter(fn (array $district) => ($district['crimes_in_from_year'] ?? 0) >= self::FewestToCompare)
                            ->filter(fn (array $district) => $order === 'biggest_fall' ? $district['change_percent'] < 0 : $district['change_percent'] > 0)
                            ->take(2)->map($brief)->values()->all();
                    }
                }

                $facts['police_districts'] = $districts + ['note' => $most['note']];
            } catch (InvalidArgumentException) {
                // No police district figures that year after all.
            }
        }

        return $facts;
    }

    /**
     * @param  array{year: ?int, region: ?string, shade: ?string, pins: ?string, raised: ?bool}  $view
     *
     * @throws AskProblem
     */
    private function describe(array $view): string
    {
        $client = $this->client();
        $given = $this->facts($view);
        $messages = [['role' => 'user', 'content' => json_encode($given, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]];

        for ($try = 1; $try <= 2; $try++) {
            $response = $this->request($client, $this->instructions(), $messages);
            $text = $this->textOf($response);

            if (MapView::backedBy($text, $given)) {
                return $text;
            }

            // Once more, told why.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => 'A figure in that isn\'t in the facts. Write it again with only the facts\' figures, as they are or rounded.'];
        }

        Log::warning("What am I looking at?: a figure wasn't in the facts, twice.", ['view' => $view]);

        throw new AskProblem(__("This view couldn't be described just now. Try another, or again later."), 502);
    }

    /**
     * What Claude is told: how to describe a view from its facts, in the page's language. The same for every view in
     * a language, so it's cached.
     */
    private function instructions(): string
    {
        $language = app()->getLocale() === 'ms' ? 'Bahasa Melayu' : 'English';

        return <<<TEXT
            You describe what a visitor is looking at on the crime map in Crimeify, a web app about crimes reported to the police in Malaysia, from the facts you're given as JSON: the year, the place chosen, how the states compare per 100,000 people, and with bubbles, the police districts with the most of a crime and the biggest changes.

            How to write it:
            - Two or three short sentences, in {$language}: the year and place first, then what stands out. Plain text, no Markdown, lists or headings.
            - Use only the facts' figures, as they are or rounded. Never work out a new figure, like a difference or a total, and never give one from memory.
            - Say a police district's figures are counts, not per person, if you give them.
            - Don't call a place safe or dangerous. Say how crime changed and where there's more or less of it per person.
            TEXT;
    }
}
