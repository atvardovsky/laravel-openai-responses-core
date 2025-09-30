<?php

namespace Atvardovsky\LaravelOpenAIResponses\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ToolCalled
{
    use Dispatchable;

    public function __construct(
        public string $toolName,
        public array $arguments,
        public mixed $result,
        public float $duration,
        public ?string $requestId = null
    ) {}
}
