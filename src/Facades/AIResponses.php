<?php

namespace Atvardovsky\LaravelOpenAIResponses\Facades;

use Atvardovsky\LaravelOpenAIResponses\Services\AIResponsesService;
use Illuminate\Support\Facades\Facade;

/**
 * Laravel Facade for AIResponsesService.
 * 
 * Provides static method access to the AIResponsesService instance through Laravel's
 * facade pattern. Enables clean, expressive syntax for AI API interactions while
 * maintaining all the benefits of dependency injection and service configuration.
 *
 * @package Atvardovsky\LaravelOpenAIResponses\Facades
 * @version 1.0.0
 * @since 2025-09-30
 * 
 * @example
 * ```php
 * // Basic usage
 * $response = AIResponses::respond([
 *     ['role' => 'user', 'content' => 'Hello!']
 * ]);
 * 
 * // With tools and files
 * $response = AIResponses::withTools(['weather'])
 *     ->withFiles(['chart.png'])
 *     ->respond([
 *         ['role' => 'user', 'content' => 'Analyze this chart']
 *     ]);
 * 
 * // Streaming
 * foreach (AIResponses::stream([
 *     ['role' => 'user', 'content' => 'Tell me a story']
 * ]) as $chunk) {
 *     echo $chunk['choices'][0]['delta']['content'] ?? '';
 * }
 * ```
 * 
 * @method static array respond(array $messages, array $options = []) Send synchronous request to OpenAI API
 * @method static \Generator stream(array $messages, array $options = []) Send streaming request to OpenAI API
 * @method static \Atvardovsky\LaravelOpenAIResponses\Services\AIResponsesService withTools(array $tools) Configure tools for subsequent requests
 * @method static \Atvardovsky\LaravelOpenAIResponses\Services\AIResponsesService withFiles(array $files) Configure files for subsequent requests
 * 
 * @see \Atvardovsky\LaravelOpenAIResponses\Services\AIResponsesService For detailed method documentation
 */
class AIResponses extends Facade
{
    /**
     * Get the registered name of the component.
     * 
     * Returns the service class name that this facade provides access to.
     * Laravel's service container will resolve this to the configured instance.
     *
     * @return string The service class name to resolve from the container
     * 
     * @since 1.0.0
     */
    protected static function getFacadeAccessor(): string
    {
        return AIResponsesService::class;
    }
}
