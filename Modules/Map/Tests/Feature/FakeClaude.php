<?php

namespace Modules\Map\Tests\Feature;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The Claude API, as fake, for AskCrimeifyTest and MapAiTest: each request's headers and JSON body kept, and answered in turn.
 */
final class FakeClaude implements ClientInterface
{
    /**
     * @var list<array{headers: array<string, list<string>>, body: array<string, mixed>}>
     */
    public array $sent = [];

    /**
     * @param  list<ResponseInterface>  $responses
     */
    public function __construct(private array $responses) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = [
            'headers' => array_change_key_case($request->getHeaders()),
            'body' => json_decode((string) $request->getBody(), true),
        ];

        return array_shift($this->responses) ?? throw new RuntimeException('Claude has nothing more to say.');
    }
}
