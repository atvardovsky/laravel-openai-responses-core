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
     * Default tools to include in requests.
     * 
     * @var array $defaultTools Tools set via withTools()
     */
    private array $defaultTools = [];

    /**
     * Default files to include in requests.
     * 
     * @var array $defaultFiles Files set via withFiles()
     */
    private array $defaultFiles = [];

    /**
     * Create a new AI Responses Service instance.
     * 
     * Initializes the service with configuration, tool registry, and event dispatcher.
     * Validates configuration on instantiation to fail fast on misconfiguration.
     *
     * @param array $config Service configuration including API keys, timeouts, validation rules
     * @param ToolRegistry $toolRegistry Registry for managing callable tools
     * @param Dispatcher $events Laravel event dispatcher for firing service events
     * @param array $defaultTools Default tools to include
     * @param array $defaultFiles Default files to include
     * 
     * @throws AIResponseException If required configuration fields are missing
     * 
     * @since 1.0.0
     */
    public function __construct(
        array $config,
        ToolRegistry $toolRegistry,
        Dispatcher $events,
        array $defaultTools = [],
        array $defaultFiles = []
    ) {
        $this->config = $config;
        $this->toolRegistry = $toolRegistry;
        $this->events = $events;
        $this->defaultTools = $defaultTools;
        $this->defaultFiles = $defaultFiles;
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
     *                      - 'max_tokens' or 'max_output_tokens' (int): Maximum tokens in response
     *                      - 'tools' (array): Array of tool definitions. For file_search, include vector_store_ids in tool object
     *                      - 'auto_execute_tools' (bool): Auto-execute tool calls (default: true)
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
            
            // Auto-execute tools if enabled and tools are present
            if ($this->shouldAutoExecuteTools($options) && !empty($context['tools'])) {
                $response = $this->executeToolLoop($messages, $response, $options, $requestId);
            }
            
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
        $usageData = null;
        
        try {
            foreach ($this->makeStreamingRequest($context['payload']) as $chunk) {
                $chunkCount++;
                
                // Capture usage data from final chunk (when stream.include_usage is true)
                if (isset($chunk['usage'])) {
                    $usageData = $chunk;
                }
                
                yield $chunk;
            }
            
            $duration = microtime(true) - $startTime;
            $metrics = ['streaming' => true, 'chunks' => $chunkCount];
            
            // Add cost metrics if usage data was received
            if ($usageData) {
                $metrics = array_merge($metrics, $this->buildMetrics($usageData, $duration));
            }
            
            $this->events->dispatch(new AfterResponse(
                $context['payload'], 
                $usageData ?? [], 
                $duration, 
                $metrics, 
                $requestId
            ));
        } catch (RateLimitException $e) {
            $duration = microtime(true) - $startTime;
            $this->events->dispatch(new AfterResponse(
                $context['payload'], 
                [], 
                $duration, 
                ['error' => $e->getMessage(), 'chunks' => $chunkCount], 
                $requestId
            ));
            throw $e;
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
        // Return new instance with tools persisted (thread-safe)
        $mergedTools = array_values(array_unique([...$this->defaultTools, ...$tools]));
        return new self($this->config, $this->toolRegistry, $this->events, $mergedTools, $this->defaultFiles);
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
        // Return new instance with files persisted (thread-safe)
        $mergedFiles = array_merge($this->defaultFiles, $files);
        return new self($this->config, $this->toolRegistry, $this->events, $this->defaultTools, $mergedFiles);
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
        
        // Merge default tools/files with options
        $tools = array_values(array_unique([...$this->defaultTools, ...($options['tools'] ?? [])], SORT_REGULAR));
        $files = array_merge($this->defaultFiles, $options['files'] ?? []);
        
        if (!empty($tools)) {
            $this->validateTools($tools);
        }
        
        if (!empty($files)) {
            $this->validateFiles($files);
        }
        
        // Responses API uses 'input' instead of 'messages'
        $processedMessages = $this->processMessages($messages, $files);
        
        $payload = [
            'model' => $this->getModel($options),
            'input' => $processedMessages,
            'max_output_tokens' => $this->getMaxTokens($options), // Responses API parameter
            'temperature' => $this->getTemperature($options),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->processTools($tools);
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto'; // Let AI decide when to use tools
            $payload['parallel_tool_calls'] = $options['parallel_tool_calls'] ?? true; // CRITICAL: Enable multiple tool calls
        }

        if ($options['stream'] ?? false) {
            $payload['stream'] = true;
        }
        
        // DEBUG: Log payload to verify parallel_tool_calls is set
        \Log::info('🔧 AI REQUEST PAYLOAD', [
            'has_tools' => !empty($tools),
            'tool_count' => count($tools ?? []),
            'tool_choice' => $payload['tool_choice'] ?? 'none',
            'parallel_tool_calls' => $payload['parallel_tool_calls'] ?? false,
            'tools' => array_map(fn($t) => $t['name'] ?? $t['type'] ?? 'unknown', $payload['tools'] ?? [])
        ]);

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
            if (!isset($message['role'])) {
                throw new AIResponseException('Each message must have a role');
            }
            
            if (!in_array($message['role'], ['system', 'user', 'assistant', 'tool'])) {
                throw new AIResponseException('Invalid message role: ' . $message['role']);
            }

            // Assistant messages with tool_calls don't require content
            // Tool messages must have tool_call_id
            // Other messages must have content
            $hasToolCalls = isset($message['tool_calls']) && !empty($message['tool_calls']);
            $hasContent = isset($message['content']);
            
            if ($message['role'] === 'tool' && !isset($message['tool_call_id'])) {
                throw new AIResponseException('Tool messages must include tool_call_id');
            }
            
            if (!$hasContent && !$hasToolCalls) {
                throw new AIResponseException('Message must have either content or tool_calls');
            }

            // Validate content if present
            if ($hasContent) {
                $content = $message['content'];
                
                // Validate content type (string or array for multimodal)
                if (!is_string($content) && !is_array($content)) {
                    throw new AIResponseException('Message content must be string or array');
                }

                // For string content, check length
                if (is_string($content)) {
                    $maxLength = $this->config['validation']['max_message_length'] ?? 100000;
                    if (strlen($content) > $maxLength) {
                        throw new AIResponseException("Message content too long. Maximum allowed: {$maxLength} characters");
                    }
                }

                // For array content (multimodal), validate structure
                if (is_array($content)) {
                    foreach ($content as $item) {
                        if (!isset($item['type'])) {
                            throw new AIResponseException('Multimodal content items must have a type');
                        }
                        if (!in_array($item['type'], ['text', 'image_url'])) {
                            throw new AIResponseException('Invalid content type: ' . $item['type']);
                        }
                    }
                }
            }
        }
    }

    private function validateTools(array $tools): void
    {
        foreach ($tools as $tool) {
            if (is_string($tool) && !$this->toolRegistry->isRegistered($tool)) {
                throw new AIResponseException("Tool '{$tool}' is not registered");
            }
            
            // Validate array tool schemas
            if (is_array($tool)) {
                // If it has 'type' and 'function', validate the function schema
                if (isset($tool['type'], $tool['function'])) {
                    $this->validateToolSchema($tool['function']);
                } elseif (isset($tool['name'])) {
                    // Direct function schema without wrapper
                    $this->validateToolSchema($tool);
                }
            }
        }
    }

    private function validateToolSchema(array $schema): void
    {
        if (!isset($schema['name'])) {
            throw new AIResponseException('Tool schema must have a name');
        }
        
        if (!isset($schema['parameters']) || !is_array($schema['parameters'])) {
            throw new AIResponseException('Tool schema must have parameters object');
        }
        
        if (!isset($schema['parameters']['type']) || $schema['parameters']['type'] !== 'object') {
            throw new AIResponseException('Tool parameters must be of type "object"');
        }
    }

    private function validateFiles(array $files): void
    {
        $allowedTypes = $this->config['files']['allowed_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = $this->config['files']['max_size'] ?? 20 * 1024 * 1024;
        $maxCount = $this->config['files']['max_count'] ?? 10;

        if (count($files) > $maxCount) {
            throw new AIResponseException("Too many files: maximum {$maxCount} files allowed");
        }

        foreach ($files as $file) {
            if (is_string($file)) {
                // Validate file path
                if (!file_exists($file)) {
                    throw new AIResponseException("File not found: {$file}");
                }
                
                $size = filesize($file);
                if ($size === false || $size <= 0) {
                    throw new AIResponseException("Cannot read file size: {$file}");
                }
                
                if ($size > $maxSize) {
                    throw new AIResponseException("File too large: {$file} ({$size} bytes, max: {$maxSize})");
                }

                $mimeType = mime_content_type($file);
                if (!$mimeType || !in_array($mimeType, $allowedTypes, true)) {
                    throw new AIResponseException("File type not allowed: {$mimeType}");
                }
            } elseif (is_array($file)) {
                // Validate pre-processed file arrays
                if (isset($file['type']) && $file['type'] === 'image_url' && isset($file['image_url']['url'])) {
                    $url = $file['image_url']['url'];
                    
                    // Only allow data: URIs for security (no remote fetching)
                    if (!str_starts_with($url, 'data:')) {
                        throw new AIResponseException("Only data: URIs are allowed for array files");
                    }
                    
                    // Parse data URI: data:[<mediatype>][;base64],<data>
                    if (!preg_match('/^data:([^;,]+)(?:;base64)?,(.+)$/', $url, $matches)) {
                        throw new AIResponseException("Invalid data: URI format");
                    }
                    
                    $mimeType = $matches[1];
                    $data = $matches[2];
                    
                    // Validate MIME type
                    if (!in_array($mimeType, $allowedTypes, true)) {
                        throw new AIResponseException("File type not allowed in data URI: {$mimeType}");
                    }
                    
                    // Estimate decoded size (base64 is ~1.37x larger than binary)
                    $estimatedSize = strlen($data) * 0.75; // Rough estimate
                    if ($estimatedSize > $maxSize) {
                        throw new AIResponseException("File data too large: estimated {$estimatedSize} bytes, max: {$maxSize}");
                    }
                } else {
                    throw new AIResponseException("Array files must have 'type' => 'image_url' and 'image_url' => ['url' => 'data:...']");
                }
            } else {
                throw new AIResponseException("Files must be string paths or arrays with image_url structure");
            }
        }
    }

    private function validateOptions(array $options): void
    {
        if (isset($options['temperature']) && ($options['temperature'] < 0 || $options['temperature'] > 2)) {
            throw new AIResponseException('Temperature must be between 0 and 2');
        }
        
        // Support both max_output_tokens (Responses API) and max_tokens (backward compat)
        $maxTokens = $options['max_output_tokens'] ?? $options['max_tokens'] ?? null;
        if ($maxTokens !== null && $maxTokens < 1) {
            throw new AIResponseException('max_output_tokens must be greater than 0');
        }
    }

    private function getModel(array $options): string
    {
        return $options['model'] ?? $this->config['default_model'];
    }

    private function getMaxTokens(array $options): int
    {
        // Prefer max_output_tokens (Responses API), fall back to max_tokens (backward compat)
        return $options['max_output_tokens'] 
            ?? $options['max_tokens'] 
            ?? $this->config['max_tokens'] 
            ?? 4000;
    }

    private function getTemperature(array $options): float
    {
        return $options['temperature'] ?? $this->config['temperature'] ?? 0.7;
    }

    /**
     * Process messages into Responses API input format
     * Converts Chat Completions message format to Responses API input items
     */
    private function processMessages(array $messages, array $files = []): array
    {
        $inputItems = [];
        
        // Find the last user message index to attach files only once
        $lastUserIndex = null;
        foreach (array_reverse(array_keys($messages), true) as $idx) {
            if (($messages[$idx]['role'] ?? null) === 'user' && !isset($messages[$idx]['type'])) {
                $lastUserIndex = $idx;
                break;
            }
        }
        
        foreach ($messages as $index => $message) {
            // If message already has 'type' (already formatted for Responses API), pass through
            if (isset($message['type'])) {
                $inputItems[] = $message;
                continue;
            }
            
            $role = $message['role'] ?? null;
            if (!$role) {
                continue; // Skip messages without role
            }
            
            $content = $message['content'] ?? null;
            
            // Only attach files to the last user message to avoid duplication
            $messageFiles = ($role === 'user' && $index === $lastUserIndex) ? $files : [];
            
            // Handle different message types for Responses API
            if ($role === 'system') {
                // System messages become developer role in Responses API
                $inputItems[] = [
                    'role' => 'developer',
                    'content' => $this->formatContent($content, [], $role)
                ];
            } elseif ($role === 'user') {
                $inputItems[] = [
                    'role' => 'user',
                    'content' => $this->formatContent($content, $messageFiles, $role)
                ];
            } elseif ($role === 'assistant') {
                // Assistant messages with tool_calls must be converted to function_call items for Responses API
                if (isset($message['tool_calls'])) {
                    // Convert each tool_call to function_call item
                    foreach ($message['tool_calls'] as $toolCall) {
                        $callId = $toolCall['id'] ?? null;
                        $functionName = $toolCall['function']['name'] ?? null;
                        
                        if (!$callId || !$functionName) {
                            throw new AIResponseException('Assistant tool_calls require non-empty id and function.name');
                        }
                        
                        $arguments = $toolCall['function']['arguments'] ?? '{}';
                        // Validate arguments is string or array
                        if (is_array($arguments)) {
                            try {
                                $arguments = json_encode($arguments, JSON_THROW_ON_ERROR);
                            } catch (\JsonException $e) {
                                throw new AIResponseException('Failed to encode tool_call arguments: ' . $e->getMessage());
                            }
                        } elseif (!is_string($arguments)) {
                            throw new AIResponseException('Tool_call arguments must be string or array');
                        }
                        
                        $inputItems[] = [
                            'type' => 'function_call',
                            'call_id' => $callId,
                            'name' => $functionName,
                            'arguments' => $arguments
                        ];
                    }
                    // Also include assistant content if present
                    if (!empty($content)) {
                        $inputItems[] = [
                            'role' => 'assistant',
                            'content' => $this->formatContent($content, [], $role)
                        ];
                    }
                    continue;
                }
                
                // Regular assistant message without tool_calls
                $inputItems[] = [
                    'role' => 'assistant',
                    'content' => $this->formatContent($content, [], $role)
                ];
            } elseif ($role === 'tool') {
                // Tool messages become function_call_output items
                $callId = $message['tool_call_id'] ?? null;
                if (!$callId) {
                    throw new AIResponseException('Tool messages require non-empty tool_call_id');
                }
                
                // Encode output with error handling
                $output = $content;
                if (!is_string($output)) {
                    try {
                        $output = json_encode($content, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw new AIResponseException('Failed to encode tool message content: ' . $e->getMessage());
                    }
                }
                
                $inputItems[] = [
                    'type' => 'function_call_output',
                    'call_id' => $callId,
                    'output' => $output
                ];
            }
        }
        
        return $inputItems;
    }
    
    /**
     * Format content for Responses API with proper type structure
     */
    private function formatContent(string|array|null $content, array $files = [], string $role = 'user'): array
    {
        $formatted = [];
        
        // Handle string content
        if (is_string($content)) {
            // user/developer/system = input_text, assistant = output_text
            $type = ($role === 'assistant') ? 'output_text' : 'input_text';
            $formatted[] = [
                'type' => $type,
                'text' => $content
            ];
        } elseif (is_array($content)) {
            // Content is already structured (multimodal)
            foreach ($content as $item) {
                if (isset($item['type'])) {
                    // Map Chat Completions types to Responses API types
                    if ($item['type'] === 'text') {
                        $type = ($role === 'assistant') ? 'output_text' : 'input_text';
                        $formatted[] = [
                            'type' => $type,
                            'text' => $item['text'] ?? ''
                        ];
                    } elseif ($item['type'] === 'image_url') {
                        $formatted[] = [
                            'type' => 'input_image',
                            'image_url' => $item['image_url']
                        ];
                    } else {
                        $formatted[] = $item; // Pass through unknown types
                    }
                } else {
                    $formatted[] = $item;
                }
            }
        }
        
        // Attach files (only called for last user message after fixes)
        if (!empty($files) && $role === 'user') {
            foreach ($files as $file) {
                if (is_array($file)) {
                    // Accept documented array format: {type: 'image_url', image_url: {url: '...'}}
                    if (isset($file['type']) && $file['type'] === 'image_url' && isset($file['image_url']['url'])) {
                        $formatted[] = [
                            'type' => 'input_image',
                            'image_url' => $file['image_url']
                        ];
                    }
                } elseif (is_string($file) && file_exists($file)) {
                    // Encode local file (already validated by validateFiles)
                    $mimeType = mime_content_type($file);
                    
                    if (str_starts_with($mimeType, 'image/')) {
                        $formatted[] = [
                            'type' => 'input_image',
                            'image_url' => [
                                'url' => 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($file))
                            ]
                        ];
                    }
                }
            }
        }
        
        return $formatted;
    }

    private function processTools(array $tools): array
    {
        return array_map(function ($tool) {
            // Handle string tool names (registered functions)
            if (is_string($tool) && $this->toolRegistry->isRegistered($tool)) {
                $schema = $this->toolRegistry->getSchema($tool);
                // Responses API expects: {"type": "function", "name": "...", "description": "...", "parameters": {...}}
                return array_merge(['type' => 'function'], $schema);
            }
            
            // Handle array tools
            if (is_array($tool)) {
                // If it's file_search or other built-in tools (has type but no name), return as-is
                if (isset($tool['type']) && !isset($tool['name'])) {
                    return $tool;
                }
                
                // If it has 'name' but no 'type', it's a function schema - add type
                if (isset($tool['name']) && !isset($tool['type'])) {
                    return array_merge(['type' => 'function'], $tool);
                }
                
                // If it has both 'type' and either 'name' or is complete, return as-is
                if (isset($tool['type'])) {
                    return $tool;
                }
                
                // Fallback: assume it's a function schema without type
                return array_merge(['type' => 'function'], $tool);
            }
            
            return $tool;
        }, $tools);
    }

    private function makeRequest(array $payload): array
    {
        $response = $this->getHttpClient()
            ->post('/responses', $payload);

        if (!$response->successful()) {
            if ($response->status() === 429) {
                $this->handleRateLimit($response);
            }
            
            $response->throw();
        }

        $responsesData = $response->json();
        
        // Transform Responses API format to Chat Completions-like format for backward compatibility
        return $this->transformResponseToChatFormat($responsesData);
    }
    
    /**
     * Transform Responses API response to Chat Completions-like format
     * This provides backward compatibility with existing code
     */
    private function transformResponseToChatFormat(array $responsesData): array
    {
        // Map Responses API usage field to Chat Completions format
        $usage = $responsesData['usage'] ?? [];
        $mappedUsage = [
            'prompt_tokens' => $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0
        ];
        if ($mappedUsage['total_tokens'] === 0 && ($mappedUsage['prompt_tokens'] > 0 || $mappedUsage['completion_tokens'] > 0)) {
            $mappedUsage['total_tokens'] = $mappedUsage['prompt_tokens'] + $mappedUsage['completion_tokens'];
        }
        
        $transformed = [
            'id' => $responsesData['id'] ?? null,
            'object' => 'chat.completion', // Maintain Chat Completions object type
            'created' => $responsesData['created'] ?? time(),
            'model' => $responsesData['model'] ?? 'unknown',
            'choices' => [],
            'usage' => $mappedUsage
        ];
        
        // Extract output_text for simple text responses
        $messageContent = $responsesData['output_text'] ?? '';
        
        // Check for function calls in output
        $output = $responsesData['output'] ?? [];
        $toolCalls = [];
        
        foreach ($output as $item) {
            if (($item['type'] ?? null) === 'function_call') {
                $args = $item['arguments'] ?? '{}';
                // Ensure arguments are JSON string for Chat Completions compatibility
                if (is_array($args)) {
                    $args = json_encode($args);
                }
                
                $toolCalls[] = [
                    'id' => $item['call_id'] ?? $item['id'] ?? 'unknown',
                    'type' => 'function',
                    'function' => [
                        'name' => $item['name'] ?? '',
                        'arguments' => $args
                    ]
                ];
            } elseif (($item['type'] ?? null) === 'message' && empty($messageContent)) {
                // Extract text from message item if output_text wasn't present
                $content = $item['content'] ?? [];
                foreach ($content as $contentItem) {
                    if (($contentItem['type'] ?? null) === 'output_text') {
                        $messageContent .= $contentItem['text'] ?? '';
                    }
                }
            }
        }
        
        // Build message object
        $message = ['role' => 'assistant'];
        
        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
            // Tool calls may not have content
            if (!empty($messageContent)) {
                $message['content'] = $messageContent;
            }
        } else {
            $message['content'] = $messageContent;
        }
        
        // Map Responses API status to Chat Completions finish_reason
        $status = $responsesData['status'] ?? 'completed';
        $finishReason = match($status) {
            'requires_action' => 'tool_calls',
            'incomplete' => 'length', // max_output_tokens reached
            'completed' => 'stop',
            default => 'stop'
        };
        
        // Check for refusal
        if (isset($responsesData['refusal']) && !empty($responsesData['refusal'])) {
            $finishReason = 'content_filter';
            $message['refusal'] = $responsesData['refusal'];
        }
        
        $transformed['choices'][] = [
            'index' => 0,
            'message' => $message,
            'finish_reason' => $finishReason
        ];
        
        return $transformed;
    }

    private function makeStreamingRequest(array $payload): \Generator
    {
        $stream = $this->getHttpClient()
            ->withHeaders([
                'Accept' => 'text/event-stream',
            ])
            ->withOptions([
                'stream' => true,
                'buffer' => false,
            ])
            ->post('/responses', $payload);

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
        // Use case-insensitive header access
        $retryAfter = $response->header('Retry-After');
        $resetTime = $retryAfter ? (is_numeric($retryAfter) ? time() + intval($retryAfter) : strtotime($retryAfter)) : 0;
        
        // Fallback to OpenAI-specific headers
        if (!$resetTime) {
            $resetTime = floatval($response->header('X-RateLimit-Reset-Requests') ?? 0);
        }
        
        $remaining = intval($response->header('X-RateLimit-Remaining-Requests') ?? 0);
        $remainingTokens = intval($response->header('X-RateLimit-Remaining-Tokens') ?? 0);
        $limit = intval($response->header('X-RateLimit-Limit-Requests') ?? 0);
        $limitTokens = intval($response->header('X-RateLimit-Limit-Tokens') ?? 0);
        
        // Determine if it's token-based or request-based rate limiting
        $type = ($remainingTokens === 0 && $limitTokens > 0) ? 'tokens' : 'requests';
        
        $this->events->dispatch(new RateLimited(
            $type,
            $type === 'tokens' ? $remainingTokens : $remaining,
            $type === 'tokens' ? $limitTokens : $limit,
            $resetTime
        ));
        
        throw new RateLimitException(
            'Rate limit exceeded. Try again later.',
            $resetTime,
            $type === 'tokens' ? $remainingTokens : $remaining
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

    private function shouldAutoExecuteTools(array $options): bool
    {
        // Allow per-request override
        if (isset($options['auto_execute_tools'])) {
            return (bool) $options['auto_execute_tools'];
        }
        
        // Use config default
        return $this->config['tools']['auto_execute'] ?? true;
    }

    /**
     * Execute tool loop for Responses API
     * Handles function_call items and creates function_call_output items
     * FIXED: Properly re-sends tools on each iteration to enable sequential tool calling
     */
    private function executeToolLoop(array $initialMessages, array $response, array $options, string $requestId): array
    {
        $maxIterations = $options['max_iterations'] ?? ($this->config['tools']['max_iterations'] ?? 8);
        $iteration = 0;
        $startTime = microtime(true);
        $maxTime = 30; // 30 second wall-clock limit
        $seenCalls = []; // Track tool calls to detect oscillations
        $messages = $initialMessages;

        while (true) {
            $iteration++;
            
            // Check iteration limit
            if ($iteration > $maxIterations) {
                \Log::warning('🔄 Tool loop stopped', ['reason' => 'max_iterations', 'iterations' => $iteration]);
                break;
            }
            
            // Check wall-clock time limit
            if (microtime(true) - $startTime > $maxTime) {
                \Log::warning('🔄 Tool loop stopped', ['reason' => 'timeout', 'elapsed' => microtime(true) - $startTime]);
                throw new AIResponseException("Tool execution exceeded time limit ({$maxTime}s)");
            }

            $choice = $response['choices'][0]['message'] ?? null;
            $toolCalls = $choice['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                \Log::info('🔄 Tool loop complete', ['iteration' => $iteration, 'reason' => 'no_more_tool_calls']);
                break;
            }

            \Log::info('🔄 Tool loop iteration', [
                'iteration' => $iteration,
                'max' => $maxIterations,
                'tool_calls_count' => count($toolCalls),
                'tool_names' => array_map(fn($c) => $c['function']['name'] ?? 'unknown', $toolCalls)
            ]);

            // Keep assistant tool_calls in transcript for context
            $messages[] = [
                'role' => 'assistant',
                'content' => $choice['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];

            // Execute tools and append results as tool messages
            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? null;
                $callId = $call['id'] ?? null;
                
                if (!$name || !$callId) {
                    throw new AIResponseException('Tool calls require non-empty id and function.name');
                }
                
                // Check if tool is registered
                if (!$this->toolRegistry->isRegistered($name)) {
                    throw new AIResponseException("Unknown tool: {$name}");
                }
                
                // Detect oscillation (same tool called repeatedly with same args)
                $argsRaw = $call['function']['arguments'] ?? '{}';
                $callSignature = md5($name . $argsRaw);
                if (isset($seenCalls[$callSignature]) && $seenCalls[$callSignature] >= 2) {
                    \Log::warning('🔄 Tool loop stopped', ['reason' => 'oscillation', 'tool' => $name]);
                    throw new AIResponseException("Tool oscillation detected: {$name} called repeatedly with same arguments");
                }
                $seenCalls[$callSignature] = ($seenCalls[$callSignature] ?? 0) + 1;

                $args = is_string($argsRaw) ? json_decode($argsRaw, true) : (is_array($argsRaw) ? $argsRaw : []);
                if ($args === null) {
                    $args = [];
                }

                try {
                    $result = $this->toolRegistry->call($name, $args);
                    
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'content' => is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR),
                    ];
                } catch (\Exception $e) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'content' => json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                    ];
                }
            }

            // CRITICAL FIX: Rebuild payload with tools each iteration
            $iterOptions = $options;
            if (empty($iterOptions['tools'])) {
                $iterOptions['tools'] = $this->defaultTools;
            }
            $iterOptions['tool_choice'] = $iterOptions['tool_choice'] ?? 'auto';

            \Log::info('🔄 Making next request in loop', [
                'iteration' => $iteration,
                'has_tools' => !empty($iterOptions['tools']),
                'tool_count' => count($iterOptions['tools'] ?? []),
                'tool_choice' => $iterOptions['tool_choice']
            ]);

            $context = $this->buildRequestContext($messages, $iterOptions);
            
            // DEFENSIVE: Ensure tools are in payload
            if (!empty($context['tools']) && empty($context['payload']['tools'])) {
                $context['payload']['tools'] = $this->processTools($context['tools']);
                $context['payload']['tool_choice'] = $iterOptions['tool_choice'] ?? 'auto';
            }
            
            $response = $this->makeRequest($context['payload']);

            if ($iteration >= $maxIterations) {
                \Log::warning('🔄 Tool loop complete', ['iteration' => $iteration, 'reason' => 'max_iterations']);
                break;
            }
        }

        return $response;
    }
}
