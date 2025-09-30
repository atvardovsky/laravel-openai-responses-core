<?php

namespace Atvardovsky\LaravelOpenAIResponses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event fired after receiving a response from OpenAI API.
 * 
 * This event is dispatched after receiving a response from OpenAI's API (successful or failed),
 * providing comprehensive information about the request, response, performance metrics,
 * and any errors that occurred. Essential for analytics, monitoring, and billing.
 *
 * @package Atvardovsky\LaravelOpenAIResponses\Events
 * @version 1.0.0
 * @since 2025-09-30
 * 
 * @example
 * ```php
 * Event::listen(AfterResponse::class, function ($event) {
 *     Log::info('AI request completed', [
 *         'request_id' => $event->requestId,
 *         'duration' => $event->duration,
 *         'tokens_used' => $event->metrics['tokens']['total_tokens'] ?? 0,
 *         'estimated_cost' => $event->metrics['estimated_cost'] ?? 0,
 *         'success' => empty($event->metrics['error'])
 *     ]);
 * });
 * ```
 */
class AfterResponse
{
    use Dispatchable;

    /**
     * Create a new AfterResponse event.
     *
     * @param array $payload Original request payload sent to OpenAI
     * @param array $response Complete response received from OpenAI (empty on error/streaming)
     * @param float $duration Request duration in seconds (including network time)
     * @param array $metrics Performance and usage metrics including:
     *                      - 'duration' (float): Processing time
     *                      - 'tokens' (array): Token usage statistics
     *                      - 'estimated_cost' (float): Calculated request cost
     *                      - 'error' (string): Error message if request failed
     *                      - 'streaming' (bool): Whether request was streamed
     *                      - 'chunks' (int): Number of chunks received (streaming only)
     * @param string|null $requestId Unique identifier matching the BeforeRequest event
     * 
     * @since 1.0.0
     */
    public function __construct(
        public array $payload,
        public array $response,
        public float $duration,
        public array $metrics = [],
        public ?string $requestId = null
    ) {}
}
