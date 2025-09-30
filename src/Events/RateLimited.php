<?php

namespace Atvardovsky\LaravelOpenAIResponses\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RateLimited
{
    use Dispatchable;

    public function __construct(
        public string $limitType,
        public int $currentUsage,
        public int $limit,
        public float $resetTime
    ) {}
}
