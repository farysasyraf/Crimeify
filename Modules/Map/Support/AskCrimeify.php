<?php

namespace Modules\Map\Support;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;

// Ask Crimeify, in the command palette (AskController, palette.js): a question about the crime figures, like "Which
// district in Johor had the biggest fall in break-ins?", answered by Claude from this app's own figures. Claude looks
// them up with AskCrimeifyTools, as many times as it needs up to config('map.ask.rounds'), then answers in a few
// sentences with links to the districts' profiles. So every number in an answer is the app's, not the model's.
//
// Through the Claude API with the official SDK and ANTHROPIC_API_KEY (config('map.ask')). The instructions and tools
// stay the same from one question to the next, so they're cached. A refused question is tried again on another model
// Anthropic picks (server-side fallbacks). Each answer is kept a while, so the same question isn't paid for twice, and
// what each one cost in tokens is logged, without the question.
final class AskCrimeify
{
    /**
     * The models that take server-side fallbacks' "default" form.
     */
    private const Fallbacks = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    /**
     * @param  ?ClientInterface  $transporter  what sends the requests, in place of the SDK's own, as in tests
     */
    public function __construct(
        private readonly AskCrimeifyTools $tools,
        private readonly ?ClientInterface $transporter = null,
    ) {}

    /**
     * Whether there's a key to ask Claude with.
     */
    public static function configured(): bool
    {
        return filled(config('map.ask.key'));
    }

    /**
     * The answer to a question, as Claude wrote it: Markdown links and lists at most.
     *
     * @return array{answer: string}
     *
     * @throws AskProblem
     */
    public function answer(string $question): array
    {
        $question = Str::squish($question);
        $key = 'ask-crimeify:'.sha1(Str::lower($question).'|'.config('map.ask.model'));

        return Cache::get($key) ?? tap(['answer' => $this->ask($question)], fn (array $answer) => Cache::put($key, $answer, now()->addMinutes(config('map.ask.cache_minutes'))));
    }

    /**
     * @throws AskProblem
     */
    private function ask(string $question): string
    {
        if (! self::configured()) {
            throw new AskProblem("Ask Crimeify isn't set up: it needs ANTHROPIC_API_KEY.");
        }

        $client = new Client(apiKey: config('map.ask.key'), requestOptions: RequestOptions::with(timeout: 60.0, transporter: $this->transporter));
        $messages = [['role' => 'user', 'content' => $question]];
        $rounds = (int) config('map.ask.rounds');
        $used = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_written' => 0];

        try {
            for ($round = 1; $round <= $rounds; $round++) {
                // The last time, no more looking up: an answer from what it has.
                $response = $this->send($client, $messages, finish: $round === $rounds);
                $used = [
                    'input' => $used['input'] + $response->usage->inputTokens,
                    'output' => $used['output'] + $response->usage->outputTokens,
                    'cache_read' => $used['cache_read'] + ($response->usage->cacheReadInputTokens ?? 0),
                    'cache_written' => $used['cache_written'] + ($response->usage->cacheCreationInputTokens ?? 0),
                ];

                if ($response->stopReason !== 'tool_use') {
                    Log::info('Ask Crimeify answered', ['model' => $response->model, 'rounds' => $round, 'stop' => $response->stopReason] + $used);

                    return $this->reply($response);
                }

                // Its turn as it came, thinking included, then what each lookup found.
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = ['role' => 'user', 'content' => $this->results($response)];
            }
        } catch (RateLimitException|OverloadedException) {
            throw new AskProblem('Ask Crimeify is busy just now. Try again in a minute.');
        } catch (AuthenticationException) {
            Log::error('Ask Crimeify: the Claude API turned down ANTHROPIC_API_KEY.');
            throw new AskProblem("Ask Crimeify isn't set up right. Tell an administrator.");
        } catch (APIStatusException $problem) {
            Log::error('Ask Crimeify: the Claude API said no.', ['status' => $problem->status, 'type' => $problem->getType(), 'message' => $problem->getAPIMessage()]);
            throw new AskProblem("Ask Crimeify couldn't answer just now. Try again in a moment.", 502);
        } catch (APIConnectionException) {
            throw new AskProblem("Ask Crimeify couldn't reach Claude just now. Try again in a moment.", 504);
        }

        throw new AskProblem('That needed more looking up than one answer allows. Try a narrower question.', 422);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function send(Client $client, array $messages, bool $finish): BetaMessage
    {
        $model = config('map.ask.model');
        $fallbacks = in_array($model, self::Fallbacks, true);

        return $client->beta->messages->create(
            maxTokens: 16000,
            messages: $messages,
            model: $model,
            cacheControl: ['type' => 'ephemeral'],
            fallbacks: $fallbacks ? 'default' : null,
            outputConfig: ['effort' => config('map.ask.effort')],
            system: [['type' => 'text', 'text' => $this->instructions()]],
            toolChoice: ['type' => $finish ? 'none' : 'auto'],
            tools: AskCrimeifyTools::definitions(),
            betas: $fallbacks ? ['server-side-fallback-2026-07-01'] : null,
        );
    }

    /**
     * What each lookup in Claude's turn found, as JSON, or why it couldn't, for Claude to try again.
     *
     * @return list<array<string, mixed>>
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
     * The answer's text, or why there isn't one.
     *
     * @throws AskProblem
     */
    private function reply(BetaMessage $response): string
    {
        // Declined, even after another model had a go.
        if ($response->stopReason === 'refusal') {
            throw new AskProblem("Ask Crimeify can't answer that. Ask about Crimeify's crime figures.", 422);
        }

        $text = trim(implode('', array_map(fn (BetaTextBlock $block) => $block->text, array_filter($response->content, fn ($block) => $block instanceof BetaTextBlock))));

        if ($text === '') {
            throw new AskProblem('No answer came back. Try asking another way.', 502);
        }

        return $text;
    }

    /**
     * What Claude is told: what the figures are and aren't, and how to answer. The same for every question, but for
     * the years, which change only with the figures, so it's cached.
     */
    private function instructions(): string
    {
        $years = StateCrime::districtYears();
        $byState = StateCrime::stateOnlyYears();
        $coverage = $years === []
            ? 'There are no figures yet.'
            : 'By police district for '.min($years).' to '.max($years).($byState === [] ? '.' : '; '.implode(', ', $byState).' by state only.');

        return <<<TEXT
            You are Ask Crimeify, in Crimeify, a web app about reported crime in Malaysia. You answer its users' questions about its crime figures, from what your tools find in them.

            The figures are crimes reported to the Royal Malaysia Police (PDRM), as the Department of Statistics Malaysia (DOSM) publishes them on data.gov.my. {$coverage} There are two categories, which together are the police's crime index, not every offence: violent crime (murder, rape, causing injury and robberies) and property crime (break-ins, vehicle thefts and other theft). Police districts aren't administrative districts, and their boundaries and populations aren't published, so a district's figures are counts, not per person. States and Malaysia also have crime per 100,000 people.

            How to answer:
            - Every figure you give comes from a tool. Never estimate one, or give one from memory. Find a place with find_places before asking for its figures.
            - If the figures can't answer the question, say so, and what they do cover.
            - Answer in a few sentences or a short list, with the figures and their years. Link each police district you name, once, as [Name](profile), with the profile address its tool gave. No other links, headings or tables.
            - Don't call a place safe or dangerous, or rank places by how safe they are. Counts follow how many people live and work in a place, and how often people report a crime differs from place to place. Compare how crime changed and which crimes are common instead, and say why when you're asked which place is safest.
            - Answer in the language of the question, Bahasa Melayu or English.
            - Answer only about Crimeify's figures. For anything else, say that's what you can help with.
            TEXT;
    }
}
