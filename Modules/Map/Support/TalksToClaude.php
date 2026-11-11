<?php

namespace Modules\Map\Support;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;
use Illuminate\Support\Facades\Log;

// The map's AI, "Ask the map" (AskTheMap) and "What am I looking at?" (ExplainView), asking Claude as Ask Crimeify
// does: the official SDK, ANTHROPIC_API_KEY and the model and effort in config('map.ask'), the instructions cached, a
// refused request tried again on another model Anthropic picks (server-side fallbacks), and each problem in words to
// show (AskProblem). What each request cost in tokens is logged, without what was asked.
trait TalksToClaude
{
    /**
     * The models that take server-side fallbacks' "default" form.
     */
    private const FallbackModels = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    /**
     * @throws AskProblem
     */
    private function client(): Client
    {
        if (! AskCrimeify::configured()) {
            throw new AskProblem(__("The map's AI isn't set up: it needs ANTHROPIC_API_KEY."));
        }

        return new Client(apiKey: config('map.ask.key'), requestOptions: RequestOptions::with(timeout: 60.0, transporter: $this->transporter));
    }

    /**
     * One request: these instructions and messages, with tools and an answer's JSON schema if given.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  array<string, mixed>|null  $schema
     *
     * @throws AskProblem
     */
    private function request(Client $client, string $instructions, array $messages, ?array $tools = null, bool $finish = false, ?array $schema = null): BetaMessage
    {
        $model = config('map.ask.model');
        $fallbacks = in_array($model, self::FallbackModels, true);

        try {
            $response = $client->beta->messages->create(
                maxTokens: 16000,
                messages: $messages,
                model: $model,
                cacheControl: ['type' => 'ephemeral'],
                fallbacks: $fallbacks ? 'default' : null,
                outputConfig: ['effort' => config('map.ask.effort')] + ($schema === null ? [] : ['format' => ['type' => 'json_schema', 'schema' => $schema]]),
                system: [['type' => 'text', 'text' => $instructions]],
                toolChoice: $tools === null ? null : ['type' => $finish ? 'none' : 'auto'],
                tools: $tools,
                betas: $fallbacks ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (RateLimitException|OverloadedException) {
            throw new AskProblem(__("The map's AI is busy just now. Try again in a minute."));
        } catch (AuthenticationException) {
            Log::error("The map's AI: the Claude API turned down ANTHROPIC_API_KEY.");
            throw new AskProblem(__("The map's AI isn't set up right. Tell an administrator."));
        } catch (APIStatusException $problem) {
            Log::error("The map's AI: the Claude API said no.", ['status' => $problem->status, 'type' => $problem->getType(), 'message' => $problem->getAPIMessage()]);
            throw new AskProblem(__("The map's AI couldn't answer just now. Try again in a moment."), 502);
        } catch (APIConnectionException) {
            throw new AskProblem(__("The map's AI couldn't reach Claude just now. Try again in a moment."), 504);
        }

        Log::info("The map's AI answered", [
            'for' => static::class,
            'model' => $response->model,
            'stop' => $response->stopReason,
            'input' => $response->usage->inputTokens,
            'output' => $response->usage->outputTokens,
            'cache_read' => $response->usage->cacheReadInputTokens ?? 0,
            'cache_written' => $response->usage->cacheCreationInputTokens ?? 0,
        ]);

        return $response;
    }

    /**
     * The answer's text, or why there isn't one.
     *
     * @throws AskProblem
     */
    private function textOf(BetaMessage $response): string
    {
        // Declined, even after another model had a go.
        if ($response->stopReason === 'refusal') {
            throw new AskProblem(__("The map's AI can't help with that. Ask about Crimeify's crime figures."), 422);
        }

        $text = trim(implode('', array_map(fn (BetaTextBlock $block) => $block->text, array_filter($response->content, fn ($block) => $block instanceof BetaTextBlock))));

        if ($text === '') {
            throw new AskProblem(__('No answer came back. Try again.'), 502);
        }

        return $text;
    }
}
