<?php

namespace Atvardovsky\LaravelOpenAIResponses\Services;

use Atvardovsky\LaravelOpenAIResponses\Events\AfterResponse;
use Atvardovsky\LaravelOpenAIResponses\Events\BeforeRequest;
use Atvardovsky\LaravelOpenAIResponses\Events\RateLimited;
use Atvardovsky\LaravelOpenAIResponses\Exceptions\AIResponseException;
use Atvardovsky\LaravelOpenAIResponses\Exceptions\RateLimitException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Main service for handling OpenAI API interactions.
 * 
 * This service provides a thread-safe, validated interface for communicating with OpenAI's API.
 * Features include request/response handling, streaming support, tool integration, file uploads,
 * comprehensive error handling, and built-in analytics logging.
 *
 * @package Atvardovsky\LaravelOpenAIResponses\Services
 * @version 1.0.0
 * @since 2025-09-30
 * 
 * @example
 * ```php
 * $service = app(AIResponsesService::class);
 * $response = $service->respond([
 *     ['role' => 'user', 'content' => 'Hello, how are you?']
 * ]);
 * ```
 */
class AIResponsesService
{
    /**
     * Configuration array for the service.
     * 
     * @var array $config Contains API keys, timeouts, validation rules, pricing info, etc.
     */
    private array $config;
    
    /**
     * Tool registry for managing available tools.
     * 
     * @var ToolRegistry $toolRegistry Registry for function calling tools
     */
    private ToolRegistry $toolRegistry;
    
    /**
     * Event dispatcher for firing service events.
     * 
     * @var Dispatcher $events Laravel event dispatcher instance
     */
    private Dispatcher $events;

    /**
     * Create a new AI Responses Service instance.
     * 
     * Initializes the service with configuration, tool registry, and event dispatcher.
     * Validates configuration on instantiation to fail fast on misconfiguration.
     *
     * @param array $config Service configuration including API keys, timeouts, validation rules
     * @param ToolRegistry $toolRegistry Registry for managing callable tools
     * @param Dispatcher $events Laravel event dispatcher for firing service events
     * 
     * @throws AIResponseException If required configuration fields are missing
     * 
     * @since 1.0.0
     */
    public function __construct(
        array $config,
        ToolRegistry $toolRegistry,
        Dispatcher $events
    ) {
        $this->config = $config;
        $this->toolRegistry = $toolRegistry;
        $this->events = $events;
        $this->validateConfiguration();
    }

    /**
     * Send a synchronous request to OpenAI API and get the complete response.
     * 
     * Processes messages through validation, handles tool integration and file attachments,
     * makes the HTTP request to OpenAI, and returns the parsed response. Fires events
     * before and after the request for monitoring and analytics.
     *
     * @param array $messages Array of conversation messages. Each message must have 'role' and 'content' fields.
     *                       Supported roles: 'system', 'user', 'assistant', 'tool'
     * @param array $options Optional request parameters:
     *                      - 'model' (string): OpenAI model to use (defaults to config default)
     *                      - 'temperature' (float): 0.0-2.0 sampling temperature
     *                      - 'max_tokens' (int): Maximum tokens in response
     *                      - 'tools' (array): Array of tool names or tool definitions
     *                      - 'files' (array): Array of file paths for vision/analysis
     * 
     * @return array Complete OpenAI API response including:
     *               - 'choices': Array of response choices with message content
     *               - 'usage': Token usage statistics
     *               - 'model': Model used for the response
     *               - 'id': Unique response identifier
     * 
     * @throws AIResponseException If validation fails, API request fails, or response is invalid
     * @throws RateLimitException If rate limits are exceeded (includes reset time and remaining quota)
     * 
     * @fires BeforeRequest Before making the API request
     * @fires AfterResponse After receiving the API response or on error
     * 
     * @example
     * ```php
     * $response = $service->respond([
     *     ['role' => 'system', 'content' => 'You are a helpful assistant.'],
     *     ['role' => 'user', 'content' => 'What is the capital of France?']
     * ], [
     *     'temperature' => 0.7,
     *     'max_tokens' => 150
     * ]);
     * 
     * echo $response['choices'][0]['message']['content'];
     * ```
     * 
     * @since 1.0.0
     * @api
     */
    public function respond(array $messages, array $options = []): array
    {
        $context = $this->buildRequestContext($messages, $options);
        $requestId = Str::uuid()->toString();
        
        $this->events->dispatch(new BeforeRequest($context['payload'], $options, $requestId));
        
        $startTime = microtime(true);
        
        try {
            $response = $this->makeRequest($context['payload']);
            $duration = microtime(true) - $startTime;
            $metrics = $this->buildMetrics($response, $duration);
            
            $this->events->dispatch(new AfterResponse(
                $context['payload'], 
                $response, 
                $duration, 
                $metrics, 
                $requestId
            ));
            
            return $response;
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->events->dispatch(new AfterResponse(
                $context['payload'], 
                [], 
                $duration, 
                ['error' => $e->getMessage()], 
                $requestId
            ));
            
            if ($e instanceof RateLimitException) {
                throw $e;
            }
            
            throw new AIResponseException("AI request failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Send a streaming request to OpenAI API and yield response chunks as they arrive.
     * 
     * Initiates a streaming connection to OpenAI's API and yields parsed JSON chunks
     * as they are received. Provides memory-safe streaming with bounded buffers to
     * prevent memory exhaustion attacks. Automatically handles stream termination.
     *
     * @param array $messages Array of conversation messages with same format as respond()
     * @param array $options Optional request parameters (same as respond() method)
     * 
     * @return \Generator<array> Generator that yields parsed JSON chunks from OpenAI.
     *                          Each chunk contains partial response data:
     *                          - 'choices': Array with delta content
     *                          - 'id': Response identifier
     *                          - 'object': Object type ('chat.completion.chunk')
     * 
     * @throws AIResponseException If validation fails, streaming fails, or buffer limits exceeded
     * @throws RateLimitException If rate limits are exceeded
     * 
     * @fires BeforeRequest Before starting the stream
     * @fires AfterResponse After stream completion or error
     * 
     * @example
     * ```php
     * foreach ($service->stream([
     *     ['role' => 'user', 'content' => 'Tell me a long story']
     * ]) as $chunk) {
     *     if (isset($chunk['choices'][0]['delta']['content'])) {
     *         echo $chunk['choices'][0]['delta']['content'];
     *     }
     * }
     * ```
     * 
     * @since 1.0.0
     * @api
     */
    public function stream(array $messages, array $options = []): \Generator
    {
        $context = $this->buildRequestContext($messages, array_merge($options, ['stream' => true]));
        $requestId = Str::uuid()->toString();
        
        $this->events->dispatch(new BeforeRequest($context['payload'], $options, $requestId));
        
        $startTime = microtime(true);
        $chunkCount = 0;
        
        try {
            foreach ($this->makeStreamingRequest($context['payload']) as $chunk) {
                $chunkCount++;
                yield $chunk;
            }
            
            $duration = microtime(true) - $startTime;
            $this->events->dispatch(new AfterResponse(
                $context['payload'], 
                [], 
                $duration, 
                ['streaming' => true, 'chunks' => $chunkCount], 
                $requestId
            ));
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->events->dispatch(new AfterResponse(
                $context['payload'], 
                [], 
                $duration, 
                ['error' => $e->getMessage(), 'chunks' => $chunkCount], 
                $requestId
            ));
            throw new AIResponseException("AI streaming failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new service instance configured to use specific tools.
     * 
     * Returns a new instance of the service (thread-safe) that will include the specified
     * tools in subsequent requests. Tools can be either registered tool names or complete
     * tool definitions conforming to OpenAI's function calling specification.
     *
     * @param array $tools Array of tool names (strings) or complete tool definitions (arrays).
     *                     Tool names must be registered in the ToolRegistry.
     *                     Complete definitions must include: name, description, parameters
     * 
     * @return self New AIResponsesService instance configured with the specified tools
     * 
     * @throws AIResponseException If any tool names are not registered in the ToolRegistry
     * 
     * @example
     * ```php
     * // Using registered tool names
     * $service = $service->withTools(['weather_tool', 'calculator']);
     * 
     * // Using complete tool definitions
     * $service = $service->withTools([
     *     [
     *         'type' => 'function',
     *         'function' => [
     *             'name' => 'get_current_weather',
     *             'description' => 'Get the current weather',
     *             'parameters' => [
     *                 'type' => 'object',
     *                 'properties' => [
     *                     'location' => ['type' => 'string']
     *                 ]
     *             ]
     *         ]
     *     ]
     * ]);
     * ```
     * 
     * @since 1.0.0
     * @api
     */
    public function withTools(array $tools): self
    {
        $this->validateTools($tools);
        // Return new instance to avoid state mutation (thread-safe)
        return new self($this->config, $this->toolRegistry, $this->events);
    }

    /**
     * Create a new service instance configured to process files.
     * 
     * Returns a new instance of the service (thread-safe) that will attach the specified
     * files to user messages in subsequent requests. Files are validated for size, type,
     * and existence before being processed. Currently supports image files for vision.
     *
     * @param array $files Array of file paths (strings) or pre-processed file data (arrays).
     *                     File paths must exist and be within size/type limits.
     *                     Pre-processed data must include 'type' and 'data' fields.
     * 
     * @return self New AIResponsesService instance configured to process the specified files
     * 
     * @throws AIResponseException If files don't exist, exceed size limits, or have invalid types
     * 
     * @example
     * ```php
     * // Using file paths (will be base64 encoded automatically)
     * $service = $service->withFiles([
     *     '/path/to/image.jpg',
     *     '/path/to/chart.png'
     * ]);
     * 
     * // Using pre-processed file data
     * $service = $service->withFiles([
     *     [
     *         'type' => 'image_url',
     *         'image_url' => [
     *             'url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD...'
     *         ]
     *     ]
     * ]);
     * ```
     * 
     * @since 1.0.0
     * @api
     */
    public function withFiles(array $files): self
    {
        $this->validateFiles($files);
        // Return new instance to avoid state mutation (thread-safe)
        return new self($this->config, $this->toolRegistry, $this->events);
    }

    private function validateConfiguration(): void
    {
        $required = $this->config['validation']['required_fields'] ?? ['api_key', 'base_url', 'default_model'];
        
        foreach ($required as $field) {
            if (empty($this->config[$field])) {
                throw new AIResponseException("Configuration field '{$field}' is required");
            }
        }
    }

    private function buildRequestContext(array $messages, array $options): array
    {
        $this->validateMessages($messages);
        $this->validateOptions($options);
        
        $tools = $options['tools'] ?? [];
        $files = $options['files'] ?? [];
        
        if (!empty($tools)) {
            $this->validateTools($tools);
        }
        
        if (!empty($files)) {
            $this->validateFiles($files);
        }
        
        $payload = [
            'model' => $this->getModel($options),
            'messages' => $this->processMessages($messages, $files),
            'max_tokens' => $this->getMaxTokens($options),
            'temperature' => $this->getTemperature($options),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->processTools($tools);
        }

        if ($options['stream'] ?? false) {
            $payload['stream'] = true;
        }

        return [
            'payload' => $payload,
            'tools' => $tools,
            'files' => $files,
        ];
    }

    private function validateMessages(array $messages): void
    {
        if (empty($messages)) {
            throw new AIResponseException('Messages array cannot be empty');
        }

        $maxMessages = $this->config['validation']['max_messages_per_request'] ?? 50;
        if (count($messages) > $maxMessages) {
            throw new AIResponseException("Too many messages. Maximum allowed: {$maxMessages}");
        }

        foreach ($messages as $message) {
            if (!isset($message['role'], $message['content'])) {
                throw new AIResponseException('Each message must have role and content');
            }
            
            if (!in_array($message['role'], ['system', 'user', 'assistant', 'tool'])) {
                throw new AIResponseException('Invalid message role: ' . $message['role']);
            }

            $maxLength = $this->config['validation']['max_message_length'] ?? 100000;
            if (strlen($message['content']) > $maxLength) {
                throw new AIResponseException("Message content too long. Maximum allowed: {$maxLength} characters");
            }
        }
    }

    private function validateTools(array $tools): void
    {
        foreach ($tools as $tool) {
            if (is_string($tool) && !$this->toolRegistry->isRegistered($tool)) {
                throw new AIResponseException("Tool '{$tool}' is not registered");
            }
        }
    }

    private function validateFiles(array $files): void
    {
        $allowedTypes = $this->config['files']['allowed_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = $this->config['files']['max_size'] ?? 20 * 1024 * 1024;

        foreach ($files as $file) {
            if (is_string($file)) {
                if (!file_exists($file)) {
                    throw new AIResponseException("File not found: {$file}");
                }
                
                $size = filesize($file);
                if ($size > $maxSize) {
                    throw new AIResponseException("File too large: {$file} ({$size} bytes)");
                }

                $mimeType = mime_content_type($file);
                if (!in_array($mimeType, $allowedTypes)) {
                    throw new AIResponseException("File type not allowed: {$mimeType}");
                }
            }
        }
    }

    private function validateOptions(array $options): void
    {
        if (isset($options['temperature']) && ($options['temperature'] < 0 || $options['temperature'] > 2)) {
            throw new AIResponseException('Temperature must be between 0 and 2');
        }
        
        if (isset($options['max_tokens']) && $options['max_tokens'] < 1) {
            throw new AIResponseException('max_tokens must be greater than 0');
        }
    }

    private function getModel(array $options): string
    {
        return $options['model'] ?? $this->config['default_model'];
    }

    private function getMaxTokens(array $options): int
    {
        return $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4000;
    }

    private function getTemperature(array $options): float
    {
        return $options['temperature'] ?? $this->config['temperature'] ?? 0.7;
    }

    private function processMessages(array $messages, array $files = []): array
    {
        foreach ($messages as &$message) {
            if (!empty($files) && $message['role'] === 'user') {
                $message['content'] = $this->attachFilesToContent($message['content'], $files);
            }
        }
        
        return $messages;
    }

    private function processTools(array $tools): array
    {
        return array_map(function ($tool) {
            if (is_string($tool) && $this->toolRegistry->isRegistered($tool)) {
                return [
                    'type' => 'function',
                    'function' => $this->toolRegistry->getSchema($tool)
                ];
            }
            
            return $tool;
        }, $tools);
    }

    private function attachFilesToContent(string $content, array $files): array
    {
        $contentArray = [['type' => 'text', 'text' => $content]];
        
        foreach ($files as $file) {
            if (is_array($file) && isset($file['type'], $file['data'])) {
                $contentArray[] = $file;
            } elseif (is_string($file) && file_exists($file)) {
                $mimeType = mime_content_type($file);
                
                if (str_starts_with($mimeType, 'image/')) {
                    $contentArray[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($file))
                        ]
                    ];
                }
            }
        }
        
        return $contentArray;
    }

    private function makeRequest(array $payload): array
    {
        $response = $this->getHttpClient()
            ->post('/chat/completions', $payload);

        if (!$response->successful()) {
            if ($response->status() === 429) {
                $this->handleRateLimit($response);
            }
            
            $response->throw();
        }

        return $response->json();
    }

    private function makeStreamingRequest(array $payload): \Generator
    {
        $stream = $this->getHttpClient()
            ->withOptions([
                'stream' => true,
                'buffer' => false,
            ])
            ->post('/chat/completions', $payload);

        if (!$stream->successful()) {
            if ($stream->status() === 429) {
                $this->handleRateLimit($stream);
            }
            
            $stream->throw();
        }

        $buffer = '';
        $chunkSize = $this->config['streaming']['chunk_size'] ?? 1024;
        $maxBufferSize = $this->config['streaming']['max_buffer_size'] ?? 64 * 1024; // 64KB limit
        $totalRead = 0;
        $maxTotalSize = $this->config['streaming']['max_total_size'] ?? 10 * 1024 * 1024; // 10MB limit
        
        while (!$stream->getBody()->eof()) {
            $chunk = $stream->getBody()->read($chunkSize);
            
            // Prevent memory exhaustion
            if (strlen($buffer) + strlen($chunk) > $maxBufferSize) {
                throw new AIResponseException('Streaming buffer overflow - response too large');
            }
            
            $totalRead += strlen($chunk);
            if ($totalRead > $maxTotalSize) {
                throw new AIResponseException('Streaming response too large - exceeded maximum size limit');
            }
            
            $buffer .= $chunk;
            
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // Keep incomplete line in buffer
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                    
                    if ($data === '[DONE]') {
                        return;
                    }
                    
                    $json = json_decode($data, true);
                    if ($json) {
                        yield $json;
                    }
                }
            }
        }
        
        // Process any remaining data in buffer
        if (!empty($buffer)) {
            $buffer = trim($buffer);
            if (str_starts_with($buffer, 'data: ')) {
                $data = substr($buffer, 6);
                if ($data !== '[DONE]') {
                    $json = json_decode($data, true);
                    if ($json) {
                        yield $json;
                    }
                }
            }
        }
    }

    private function getHttpClient(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->withToken($this->config['api_key'])
            ->timeout($this->config['timeout'])
            ->connectTimeout($this->config['connect_timeout'])
            ->withHeaders([
                'Content-Type' => 'application/json',
            ]);
    }

    private function handleRateLimit($response): void
    {
        $headers = $response->headers();
        
        $resetTime = floatval($headers['x-ratelimit-reset-requests'][0] ?? 0);
        $remaining = intval($headers['x-ratelimit-remaining-requests'][0] ?? 0);
        
        $this->events->dispatch(new RateLimited(
            'requests',
            $remaining,
            intval($headers['x-ratelimit-limit-requests'][0] ?? 0),
            $resetTime
        ));
        
        throw new RateLimitException(
            'Rate limit exceeded. Try again later.',
            $resetTime,
            $remaining
        );
    }

    private function buildMetrics(array $response, float $duration): array
    {
        if (!($this->config['metrics']['enabled'] ?? true)) {
            return [];
        }

        $metrics = ['duration' => $duration];
        
        if ($this->config['metrics']['track_tokens'] ?? true) {
            $metrics['tokens'] = $response['usage'] ?? [];
        }
        
        if ($this->config['metrics']['track_costs'] ?? false) {
            $metrics['estimated_cost'] = $this->calculateCost($response);
        }
        
        return $metrics;
    }

    private function calculateCost(array $response): float
    {
        $usage = $response['usage'] ?? [];
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        
        $model = $response['model'] ?? $this->config['default_model'];
        $pricing = $this->config['pricing'][$model] ?? null;
        
        if (!$pricing) {
            // Fallback to default gpt-4o pricing if model not configured
            $pricing = $this->config['pricing']['gpt-4o'] ?? [
                'prompt' => 0.004,
                'completion' => 0.016
            ];
        }
        
        $promptRate = $pricing['prompt'] / 1000; // per 1K tokens
        $completionRate = $pricing['completion'] / 1000; // per 1K tokens
        
        return ($promptTokens * $promptRate) + ($completionTokens * $completionRate);
    }
}
