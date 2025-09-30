<?php

namespace Atvardovsky\LaravelOpenAIResponses\Exceptions;

class RateLimitException extends AIResponseException
{
    public function __construct(
        string $message = "Rate limit exceeded",
        public readonly ?float $resetTime = null,
        public readonly ?int $remainingRequests = null
    ) {
        parent::__construct($message, 429);
    }
}
