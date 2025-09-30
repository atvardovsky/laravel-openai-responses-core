<?php

namespace Atvardovsky\LaravelOpenAIResponses\Listeners;

use Atvardovsky\LaravelOpenAIResponses\Events\AfterResponse;
use Atvardovsky\LaravelOpenAIResponses\Events\BeforeRequest;
use Atvardovsky\LaravelOpenAIResponses\Services\AILoggingService;

class LogAIRequestListener
{
    private array $requestIds = [];

    public function __construct(private AILoggingService $loggingService)
    {}

    public function handleBeforeRequest(BeforeRequest $event): void
    {
        $requestId = $this->loggingService->logRequest($event->payload, $event->options);
        $this->requestIds[spl_object_hash($event)] = $requestId;
    }

    public function handleAfterResponse(AfterResponse $event): void
    {
        $requestId = $event->requestId ?? $this->requestIds[spl_object_hash($event)] ?? null;
        
        if ($requestId) {
            $this->loggingService->logResponse($requestId, $event->response, $event->duration, $event->metrics);
            unset($this->requestIds[spl_object_hash($event)]);
        }
    }

    public function subscribe($events): array
    {
        return [
            BeforeRequest::class => 'handleBeforeRequest',
            AfterResponse::class => 'handleAfterResponse',
        ];
    }
}
