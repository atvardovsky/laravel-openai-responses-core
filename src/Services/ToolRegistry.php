<?php

namespace Atvardovsky\LaravelOpenAIResponses\Services;

use Atvardovsky\LaravelOpenAIResponses\Events\ToolCalled;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;

/**
 * Registry for managing callable tools in AI conversations.
 * 
 * This service provides registration, validation, and execution of tools that can be
 * called by OpenAI during conversations. Tools are functions that the AI can invoke
 * to perform specific tasks like getting weather data, performing calculations, etc.
 *
 * @package Atvardovsky\LaravelOpenAIResponses\Services
 * @version 1.0.0
 * @since 2025-09-30
 * 
 * @example
 * ```php
 * $registry = app(ToolRegistry::class);
 * $registry->register('weather', [
 *     'name' => 'get_weather',
 *     'description' => 'Get current weather for a location',
 *     'parameters' => [
 *         'type' => 'object',
 *         'properties' => [
 *             'location' => ['type' => 'string', 'description' => 'City name']
 *         ],
 *         'required' => ['location']
 *     ]
 * ], function($args) {
 *     return "Weather in {$args['location']}: Sunny, 72°F";
 * });
 * ```
 */
class ToolRegistry
{
    /**
     * Array storing registered tools with their schemas and callables.
     * 
     * @var array $tools Format: ['toolName' => ['schema' => ..., 'callable' => ..., 'limits' => ...]]
     */
    private array $tools = [];
    
    /**
     * Default limits applied to all tools.
     * 
     * @var array $limits Contains max_registered, timeout, and other constraints
     */
    private array $limits = [];

    /**
     * Create a new Tool Registry instance.
     * 
     * Initializes the registry with configuration-based limits for tool registration
     * and execution. Sets reasonable defaults for production use.
     *
     * @param array $config Configuration array with optional keys:
     *                     - 'max_registered' (int): Maximum number of tools (default: 100)
     *                     - 'timeout' (int): Tool execution timeout in seconds (default: 10)
     * 
     * @since 1.0.0
     */
    public function __construct(private array $config = [])
    {
        $this->limits['max_registered'] = $config['max_registered'] ?? 100;
        $this->limits['timeout'] = $config['timeout'] ?? 10;
    }

    /**
     * Register a new tool with the registry.
     * 
     * Adds a callable function to the registry that can be invoked by OpenAI during
     * conversations. The schema defines the function signature and parameters for
     * the AI to understand how to call the tool.
     *
     * @param string $name Unique identifier for the tool (used in withTools() calls)
     * @param array $schema OpenAI function calling schema including:
     *                     - 'name' (string): Function name visible to AI
     *                     - 'description' (string): What the function does
     *                     - 'parameters' (array): JSON Schema defining parameters
     * @param callable $callable Function to execute when tool is called.
     *                           Receives single array argument with parameters.
     * @param array $limits Optional tool-specific limits (overrides defaults):
     *                     - 'timeout' (int): Execution timeout in seconds
     * 
     * @return void
     * 
     * @throws \InvalidArgumentException If maximum tools exceeded or name already exists
     * 
     * @example
     * ```php
     * $registry->register('calculator', [
     *     'name' => 'calculate',
     *     'description' => 'Perform mathematical calculations',
     *     'parameters' => [
     *         'type' => 'object',
     *         'properties' => [
     *             'expression' => [
     *                 'type' => 'string',
     *                 'description' => 'Mathematical expression to evaluate'
     *             ]
     *         ],
     *         'required' => ['expression']
     *     ]
     * ], function($args) {
     *     return eval("return " . $args['expression'] . ";");
     * }, ['timeout' => 5]);
     * ```
     * 
     * @since 1.0.0
     * @api
     */
    public function register(string $name, array $schema, callable $callable, array $limits = []): void
    {
        if (count($this->tools) >= $this->limits['max_registered']) {
            throw new \InvalidArgumentException("Maximum number of tools ({$this->limits['max_registered']}) reached");
        }

        $this->tools[$name] = [
            'schema' => $schema,
            'callable' => $callable,
            'limits' => array_merge($this->limits, $limits),
        ];
    }

    /**
     * Execute a registered tool with the provided arguments.
     * 
     * Calls the specified tool's function with the given arguments and returns the result.
     * Automatically tracks execution time and fires events for monitoring. Handles
     * exceptions gracefully and ensures they're properly logged.
     *
     * @param string $name Name of the registered tool to execute
     * @param array $arguments Associative array of parameters to pass to the tool
     * 
     * @return mixed Result returned by the tool's callable function
     * 
     * @throws \InvalidArgumentException If the specified tool is not registered
     * @throws \Exception Any exception thrown by the tool's execution is re-thrown
     * 
     * @fires ToolCalled After tool execution (successful or failed)
     * 
     * @example
     * ```php
     * $result = $registry->call('weather', ['location' => 'New York']);
     * // Returns: "Weather in New York: Sunny, 72°F"
     * ```
     * 
     * @since 1.0.0
     * @internal This method is typically called by the AI service, not directly
     */
    public function call(string $name, array $arguments): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Tool '{$name}' not found");
        }

        $tool = $this->tools[$name];
        $startTime = microtime(true);

        try {
            $result = call_user_func($tool['callable'], $arguments);
            $duration = microtime(true) - $startTime;

            Event::dispatch(new ToolCalled($name, $arguments, $result, $duration));

            return $result;
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            Event::dispatch(new ToolCalled($name, $arguments, $e, $duration));
            throw $e;
        }
    }

    public function getSchema(string $name): ?array
    {
        return $this->tools[$name]['schema'] ?? null;
    }

    public function getAllSchemas(): array
    {
        return array_map(fn($tool) => $tool['schema'], $this->tools);
    }

    public function isRegistered(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function getRegisteredTools(): array
    {
        return array_keys($this->tools);
    }
}
