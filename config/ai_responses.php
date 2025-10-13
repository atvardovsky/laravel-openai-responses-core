<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI API Configuration
    |--------------------------------------------------------------------------
    */
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    
    /*
    |--------------------------------------------------------------------------
    | Default Model Settings
    |--------------------------------------------------------------------------
    */
    'default_model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o'),
    'max_tokens' => env('OPENAI_MAX_TOKENS', 4000),
    'temperature' => env('OPENAI_TEMPERATURE', 0.7),
    
    /*
    |--------------------------------------------------------------------------
    | Timeout Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('OPENAI_TIMEOUT', 30),
    'connect_timeout' => env('OPENAI_CONNECT_TIMEOUT', 10),
    
    /*
    |--------------------------------------------------------------------------
    | Metrics & Monitoring
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'enabled' => env('OPENAI_METRICS_ENABLED', true),
        'track_tokens' => env('OPENAI_TRACK_TOKENS', true),
        'track_costs' => env('OPENAI_TRACK_COSTS', false),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Logging & Analytics
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('OPENAI_LOGGING_ENABLED', true),
        'log_requests' => env('OPENAI_LOG_REQUESTS', true),
        'log_responses' => env('OPENAI_LOG_RESPONSES', true),
        'log_tools' => env('OPENAI_LOG_TOOLS', true),
        'sensitive_fields' => ['api_key'], // Fields to exclude from logs
    ],
    
    'analytics' => [
        'enabled' => env('OPENAI_ANALYTICS_ENABLED', true),
        'aggregate_daily' => env('OPENAI_AGGREGATE_DAILY', true),
        'retention_days' => env('OPENAI_RETENTION_DAYS', 90),
        'cleanup_enabled' => env('OPENAI_CLEANUP_ENABLED', true),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Streaming Configuration
    |--------------------------------------------------------------------------
    */
    'streaming' => [
        'chunk_size' => env('OPENAI_STREAM_CHUNK_SIZE', 1024),
        'max_buffer_size' => env('OPENAI_MAX_BUFFER_SIZE', 64 * 1024), // 64KB
        'max_total_size' => env('OPENAI_MAX_TOTAL_SIZE', 10 * 1024 * 1024), // 10MB
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    */
    'tools' => [
        'max_registered' => env('OPENAI_MAX_TOOLS', 100),
        'timeout' => env('OPENAI_TOOL_TIMEOUT', 10),
        'auto_execute' => env('OPENAI_AUTO_EXECUTE_TOOLS', true),
        'max_iterations' => env('OPENAI_MAX_TOOL_ITERATIONS', 30), // Increased for multi-query support
    ],
    
    /*
    |--------------------------------------------------------------------------
    | File Upload Configuration
    |--------------------------------------------------------------------------
    */
    'files' => [
        'max_size' => env('OPENAI_MAX_FILE_SIZE', 20 * 1024 * 1024), // 20MB
        'max_count' => env('OPENAI_MAX_FILE_COUNT', 10), // Maximum files per request
        'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Pricing Configuration (Updated Sep 2025 - Current OpenAI Rates)
    |--------------------------------------------------------------------------
    */
    'pricing' => [
        'gpt-4o' => [
            'prompt' => 0.004, // per 1K tokens ($4.00/1M)
            'completion' => 0.016, // per 1K tokens ($16.00/1M)
        ],
        'gpt-4o-mini' => [
            'prompt' => 0.0006, // per 1K tokens ($0.60/1M)
            'completion' => 0.0024, // per 1K tokens ($2.40/1M)
        ],
        'gpt-4-turbo' => [
            'prompt' => 0.01, // per 1K tokens (legacy model)
            'completion' => 0.03, // per 1K tokens (legacy model)
        ],
        // New GPT-5 series models (if available)
        'gpt-5' => [
            'prompt' => 0.00125, // per 1K tokens ($1.25/1M)
            'completion' => 0.01, // per 1K tokens ($10.00/1M)
        ],
        'gpt-5-mini' => [
            'prompt' => 0.00025, // per 1K tokens ($0.25/1M)
            'completion' => 0.002, // per 1K tokens ($2.00/1M)
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'max_message_length' => env('OPENAI_MAX_MESSAGE_LENGTH', 100000),
        'max_messages_per_request' => env('OPENAI_MAX_MESSAGES', 50),
        'required_fields' => ['api_key', 'base_url', 'default_model'],
    ],
];
