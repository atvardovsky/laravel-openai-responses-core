<?php

namespace Atvardovsky\LaravelOpenAIResponses\Listeners;

use Atvardovsky\LaravelOpenAIResponses\Events\ToolCalled;
use Atvardovsky\LaravelOpenAIResponses\Services\AILoggingService;

class LogToolCallListener
{
    public function __construct(private AILoggingService $loggingService)
    {}

    public function handle(ToolCalled $event): void
    {
        $requestId = $event->requestId ?? 'unknown';
        
        $error = $event->result instanceof \Throwable ? $event->result : null;
        $result = $error ? null : $event->result;

        $this->loggingService->logToolCall(
            $requestId,
            $event->toolName,
            $event->arguments,
            $result,
            $event->duration,
            $error
        );
    }
}
