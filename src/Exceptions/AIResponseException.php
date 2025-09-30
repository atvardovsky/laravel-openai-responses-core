<?php

namespace Atvardovsky\LaravelOpenAIResponses\Exceptions;

/**
 * Base exception for AI response service errors.
 * 
 * This exception is thrown for various AI service failures including validation errors,
 * API communication issues, configuration problems, and processing failures. Contains
 * optional context data for detailed error analysis and debugging.
 *
 * @package Atvardovsky\LaravelOpenAIResponses\Exceptions
 * @version 1.0.0
 * @since 2025-09-30
 * 
 * @example
 * ```php
 * try {
 *     $response = $service->respond($messages);
 * } catch (AIResponseException $e) {
 *     Log::error('AI request failed', [
 *         'message' => $e->getMessage(),
 *         'context' => $e->context,
 *         'previous' => $e->getPrevious()?->getMessage()
 *     ]);
 * }
 * ```
 */
class AIResponseException extends \Exception
{
    /**
     * Create a new AI Response Exception.
     *
     * @param string $message Human-readable error description
     * @param int $code Error code (0 for general errors, HTTP status codes for API errors)
     * @param \Throwable|null $previous Previous exception that caused this error
     * @param array|null $context Additional context data for debugging:
     *                           - Request details
     *                           - Validation failures
     *                           - API response data
     *                           - Configuration issues
     * 
     * @since 1.0.0
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?array $context = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
