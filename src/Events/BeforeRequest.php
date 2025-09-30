<?php

namespace Atvardovsky\LaravelOpenAIResponses\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event fired before making a request to OpenAI API.
 * 
 * This event is dispatched just before sending a request to OpenAI's API,
 * allowing listeners to perform logging, monitoring, or request modification.
 * Contains the complete payload that will be sent to OpenAI.
 *
 * @package Atvardovsky\LaravelOpenAIResponses\Events
 * @version 1.0.0
 * @since 2025-09-30
 * 
 * @example
 * ```php
 * Event::listen(BeforeRequest::class, function ($event) {
 *     Log::info('AI request started', [
 *         'request_id' => $event->requestId,
 *         'model' => $event->payload['model'],
 *         'message_count' => count($event->payload['messages'])
 *     ]);
 * });
 * ```
 */
class BeforeRequest
{
    use Dispatchable;

    /**
     * Create a new BeforeRequest event.
     *
     * @param array $payload Complete OpenAI API payload including:
     *                      - 'model' (string): Model name
     *                      - 'messages' (array): Conversation messages
     *                      - 'temperature' (float): Sampling temperature
     *                      - 'max_tokens' (int): Maximum response tokens
     *                      - 'tools' (array): Available tools (if any)
     *                      - 'stream' (bool): Whether streaming is enabled
     * @param array $options Original options passed to respond() or stream()
     * @param string|null $requestId Unique identifier for this request
     * 
     * @since 1.0.0
     */
    public function __construct(
        public array $payload,
        public array $options = [],
        public ?string $requestId = null
    ) {}
}
