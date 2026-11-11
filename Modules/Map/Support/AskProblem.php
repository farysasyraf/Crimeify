<?php

namespace Modules\Map\Support;

use RuntimeException;

// Why Ask Crimeify (AskCrimeify) couldn't answer, as the palette says it, with the HTTP status to send it with.
final class AskProblem extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 503)
    {
        parent::__construct($message);
    }
}
