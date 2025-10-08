# Laravel OpenAI Responses Core

A Laravel package for working with OpenAI's Chat Completions API with minimal overhead. Supports Laravel 11 and PHP 8.2+.

## Features

- **Simple API**: `respond()`, `stream()`, `withTools()`, `withFiles()` methods
- **Thread-Safe**: Immutable service instances prevent state mutation
- **Input Validation**: Comprehensive validation for security and reliability
- **Tool Registry**: Register and manage tools with limits
- **Event System**: BeforeRequest, AfterResponse, ToolCalled, RateLimited events
- **Streaming Support**: Memory-safe generator-based streaming with bounded buffers
- **Smart Cost Calculation**: Model-specific pricing with automatic updates (Sep 2025)
- **Comprehensive Analytics**: Database logging with performance metrics
- **Minimal Dependencies**: Uses only Laravel's HTTP client
- **Production Ready**: Exception handling, rate limiting, and memory protection

## Installation

```bash
composer require atvardovsky/laravel-openai-responses-core
```

Publish the configuration and run migrations:

```bash
php artisan vendor:publish --provider="Atvardovsky\LaravelOpenAIResponses\AIResponsesServiceProvider" --tag="config"
php artisan migrate
```

## Upgrading

If upgrading from an earlier version, run migrations to apply database schema updates:

```bash
php artisan migrate
```

## Configuration

Set your OpenAI API key in `.env`:

```env
OPENAI_API_KEY=your-api-key-here
OPENAI_DEFAULT_MODEL=gpt-4o
OPENAI_TIMEOUT=30

# Logging & Analytics
OPENAI_LOGGING_ENABLED=true
OPENAI_METRICS_ENABLED=true
OPENAI_ANALYTICS_ENABLED=true

# Validation limits
OPENAI_MAX_MESSAGE_LENGTH=100000
OPENAI_MAX_MESSAGES=50

# Streaming protection
OPENAI_MAX_BUFFER_SIZE=65536
OPENAI_MAX_TOTAL_SIZE=10485760

# Tool execution
OPENAI_AUTO_EXECUTE_TOOLS=true
OPENAI_MAX_TOOL_ITERATIONS=5

# File upload limits  
OPENAI_MAX_FILE_SIZE=20971520
```

## Usage

### Basic Response

```php
use Atvardovsky\LaravelOpenAIResponses\Facades\AIResponses;

$response = AIResponses::respond([
    ['role' => 'user', 'content' => 'Hello, how are you?']
]);

echo $response['choices'][0]['message']['content'];
```

### Streaming

```php
foreach (AIResponses::stream([
    ['role' => 'user', 'content' => 'Tell me a story']
]) as $chunk) {
    echo $chunk['choices'][0]['delta']['content'] ?? '';
}

// Note: Usage metrics (tokens/cost) are automatically included in streaming 
// via stream_options when metrics are enabled in configuration
```

### With Tools (Function Calling)

```php
use Atvardovsky\LaravelOpenAIResponses\Services\ToolRegistry;

// Register a tool
app(ToolRegistry::class)->register('get_weather', [
    'name' => 'get_weather',
    'description' => 'Get weather for a location',
    'parameters' => [
        'type' => 'object',
        'properties' => [
            'location' => ['type' => 'string', 'description' => 'City name']
        ],
        'required' => ['location']
    ]
], function ($args) {
    // This function executes when AI calls the tool
    return "Weather in {$args['location']}: Sunny, 72°F";
});

// Automatic tool execution (enabled by default)
$response = AIResponses::withTools(['get_weather'])->respond([
    ['role' => 'user', 'content' => 'What\'s the weather in New York?']
]);

// Tools are automatically executed and the final answer is returned
echo $response['choices'][0]['message']['content'];
// Output: "The weather in New York is sunny with a temperature of 72°F"
```

**How it works:**
1. AI receives your question and available tools
2. AI decides to call `get_weather` with `{location: "New York"}`
3. Package automatically executes your registered function
4. AI receives the result and formulates a natural answer
5. You get the final response

**Disable auto-execution** for manual control:
```php
$response = AIResponses::withTools(['get_weather'])->respond(
    $messages,
    ['auto_execute_tools' => false]
);

// Then manually handle tool_calls if needed
if (isset($response['choices'][0]['message']['tool_calls'])) {
    // Manual execution logic here
}
```

### With Files (Vision)

```php
// Files are embedded as base64 data URLs for vision/multimodal input
$response = AIResponses::withFiles([
    '/path/to/image.jpg'
])->respond([
    ['role' => 'user', 'content' => 'Describe this image']
]);
```

### Chaining

```php
$response = AIResponses::withTools(['calculator'])
    ->withFiles(['chart.png'])
    ->respond([
        ['role' => 'user', 'content' => 'Analyze this chart and calculate the average']
    ]);
```

## Events

Listen to AI response events:

```php
use Atvardovsky\LaravelOpenAIResponses\Events\BeforeRequest;
use Atvardovsky\LaravelOpenAIResponses\Events\AfterResponse;

Event::listen(BeforeRequest::class, function ($event) {
    Log::info('AI request started', $event->payload);
});

Event::listen(AfterResponse::class, function ($event) {
    Log::info('AI request completed', [
        'duration' => $event->duration,
        'metrics' => $event->metrics
    ]);
});
```

## Error Handling

The package provides comprehensive error handling with custom exceptions:

```php
use Atvardovsky\LaravelOpenAIResponses\Exceptions\AIResponseException;
use Atvardovsky\LaravelOpenAIResponses\Exceptions\RateLimitException;

try {
    $response = AIResponses::respond([
        ['role' => 'user', 'content' => 'Hello']
    ]);
} catch (RateLimitException $e) {
    // Handle rate limiting - includes reset time and remaining requests
    Log::warning('Rate limit hit', [
        'reset_time' => $e->resetTime,
        'remaining' => $e->remainingRequests
    ]);
} catch (AIResponseException $e) {
    // Handle validation errors, API failures, etc.
    Log::error('AI request failed', [
        'message' => $e->getMessage(),
        'context' => $e->context
    ]);
}
```

## Logging & Analytics

The package includes comprehensive logging and analytics features:

### Database Setup

Run migrations to set up analytics tables:

```bash
php artisan migrate

# Or publish and run them manually
php artisan vendor:publish --provider="Atvardovsky\LaravelOpenAIResponses\AIResponsesServiceProvider" --tag="migrations"
php artisan migrate
```

### Logging Configuration

Configure logging in `config/ai_responses.php`:

```php
'logging' => [
    'enabled' => true,
    'channels' => [
        'database' => true,  // Store in database
        'file' => false,     // Log to Laravel logs
    ],
    'log_requests' => true,
    'log_responses' => true,
    'log_tools' => true,
],

'analytics' => [
    'enabled' => true,
    'retention_days' => 90,
    'cleanup_enabled' => true,
],
```

### Analytics Commands

View usage analytics:

```bash
# Show analytics for date range
php artisan ai:analytics 2024-01-01 2024-01-31

# Filter by model
php artisan ai:analytics 2024-01-01 2024-01-31 --model=gpt-4o

# Export to CSV/JSON
php artisan ai:analytics 2024-01-01 2024-01-31 --export=csv
```

Aggregate daily metrics:

```bash
# Aggregate yesterday's metrics
php artisan ai:aggregate-metrics

# Aggregate specific date
php artisan ai:aggregate-metrics 2024-01-15
```

Cleanup old data:

```bash
# Clean up based on retention policy
php artisan ai:cleanup --force
```

### Accessing Analytics Data

Query analytics data directly:

```php
use Atvardovsky\LaravelOpenAIResponses\Services\AIAnalyticsService;

$analytics = app(AIAnalyticsService::class);

// Get usage stats
$stats = $analytics->getUsageStats('2024-01-01', '2024-01-31');

// Get cost analysis
$costs = $analytics->getCostAnalysis('2024-01-01', '2024-01-31');

// Get performance metrics
$performance = $analytics->getPerformanceMetrics('2024-01-01', '2024-01-31');

// Get top tools
$topTools = $analytics->getTopTools('2024-01-01', '2024-01-31');
```

### Models

Access raw data using Eloquent models:

```php
use Atvardovsky\LaravelOpenAIResponses\Models\AIRequest;
use Atvardovsky\LaravelOpenAIResponses\Models\AIMetric;
use Atvardovsky\LaravelOpenAIResponses\Models\AIToolCall;

// Recent requests
$recentRequests = AIRequest::successful()
    ->with('toolCalls')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

// Daily metrics
$dailyMetrics = AIMetric::byDateRange('2024-01-01', '2024-01-31')
    ->byModel('gpt-4o')
    ->get();
```

### Scheduled Tasks

Add to your scheduler for automatic aggregation:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('ai:aggregate-metrics')->daily();
    $schedule->command('ai:cleanup')->weekly();
    $schedule->command('ai:update-pricing --check')->daily(); // Check for pricing updates
}
```

## Pricing Management

The package includes automatic pricing management with web scraping fallback:

### Update Pricing

Check and update OpenAI model pricing:

```bash
# Check if pricing is outdated
php artisan ai:update-pricing --check

# Update pricing from OpenAI website
php artisan ai:update-pricing

# Force update (ignore cache)
php artisan ai:update-pricing --force
```

### Programmatic Access

Use the pricing service in your code:

```php
use Atvardovsky\LaravelOpenAIResponses\Services\AIPricingService;

$pricingService = app(AIPricingService::class);

// Get current pricing for all models
$pricing = $pricingService->fetchPricing();

// Get pricing for specific model
$gpt4oPricing = $pricingService->getModelPricing('gpt-4o');
// Returns: ['prompt' => 0.004, 'completion' => 0.016]

// Check if pricing is outdated
if ($pricingService->isPricingOutdated()) {
    Log::warning('OpenAI pricing may be outdated');
}
```

## Testing

Run the tests:

```bash
composer test
```

## Configuration Options

All configuration options in `config/ai_responses.php`:

- **API Settings**: API key, base URL, default model
- **Timeouts**: Request and connection timeouts
- **Rate Limiting**: Requests and tokens per minute
- **Metrics**: Enable/disable tracking and cost calculation
- **Streaming**: Chunk size and buffering options
- **Tools**: Maximum registered tools and timeout

## Requirements

- PHP 8.2+
- Laravel 11.0+

## API Documentation

The package includes comprehensive PHPDoc annotations compatible with Laravel API Documentation Generator:

```bash
# Install the documentation generator
composer require --dev mpociot/laravel-apidoc-generator

# Generate API documentation
php artisan apidoc:generate

# View the generated documentation
open public/docs/index.html
```

### Available Documentation

- **Service Classes**: Complete method signatures, parameters, return types, and examples
- **Event Classes**: Event properties, usage patterns, and listener examples  
- **Model Classes**: Database fields, relationships, and query scopes
- **Exception Classes**: Error contexts, handling strategies, and debugging info
- **Configuration**: All config options with descriptions and defaults

### IDE Support

The package provides full IDE autocomplete and type checking through:

- PHPDoc annotations for all public methods
- Generic type hints for collections and generators
- Property-level documentation for models
- Exception context documentation

## License

MIT License