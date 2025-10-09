<?php

namespace Atvardovsky\LaravelOpenAIResponses\Services;

use Atvardovsky\LaravelOpenAIResponses\Models\AIRequest;
use Atvardovsky\LaravelOpenAIResponses\Models\AIToolCall;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AILoggingService
{
    public function __construct(private array $config)
    {}

    public function logRequest(array $payload, array $options = []): string
    {
        $requestId = Str::uuid()->toString();
        
        if (!$this->shouldLog('requests')) {
            return $requestId;
        }

        // Normalize input for logging (Responses API uses 'input', Chat Completions uses 'messages')
        $inputData = $payload['input'] ?? $payload['messages'] ?? [];
        
        $data = [
            'request_id' => $requestId,
            'model' => $payload['model'] ?? 'unknown',
            'messages' => $this->sanitizeData($inputData), // Store in 'messages' column for compatibility
            'options' => $this->sanitizeData($options),
            'tools' => $payload['tools'] ?? null,
            'files' => $this->extractFileInfo($options['files'] ?? []),
            'status' => 'pending',
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'user_id' => auth()?->id(),
        ];

        $this->storeToDatabase($data);
        $this->storeToFile('request', $data);

        return $requestId;
    }

    public function logResponse(string $requestId, array $response, float $duration, array $metrics = []): void
    {
        if (!$this->shouldLog('responses')) {
            return;
        }

        $updateData = [
            'response' => $this->sanitizeData($response),
            'status' => 'completed',
            'duration_ms' => round($duration * 1000),
        ];

        if (isset($response['usage'])) {
            $updateData['prompt_tokens'] = $response['usage']['prompt_tokens'] ?? 0;
            $updateData['completion_tokens'] = $response['usage']['completion_tokens'] ?? 0;
            $updateData['total_tokens'] = $response['usage']['total_tokens'] ?? 0;
        }

        if (isset($metrics['estimated_cost'])) {
            $updateData['estimated_cost'] = $metrics['estimated_cost'];
        }

        $this->updateDatabase($requestId, $updateData);
        $this->storeToFile('response', array_merge(['request_id' => $requestId], $updateData));
    }

    public function logError(string $requestId, \Throwable $error): void
    {
        $data = [
            'status' => 'failed',
            'error_message' => $error->getMessage(),
        ];

        $this->updateDatabase($requestId, $data);
        $this->storeToFile('error', [
            'request_id' => $requestId,
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ]);
    }

    public function logStreamingStart(string $requestId): void
    {
        $this->updateDatabase($requestId, ['status' => 'streaming']);
    }

    public function logToolCall(string $requestId, string $toolName, array $arguments, $result, float $duration, ?\Throwable $error = null): void
    {
        if (!$this->shouldLog('tools')) {
            return;
        }

        $data = [
            'request_id' => $requestId,
            'tool_name' => $toolName,
            'arguments' => $this->sanitizeData($arguments),
            'result' => $this->sanitizeData($result),
            'status' => $error ? 'error' : 'success',
            'error_message' => $error?->getMessage(),
            'duration_ms' => round($duration * 1000),
        ];

        AIToolCall::create($data);
        $this->storeToFile('tool_call', $data);
    }

    private function shouldLog(string $type): bool
    {
        return ($this->config['logging']['enabled'] ?? true) 
            && ($this->config['logging']["log_{$type}"] ?? true);
    }

    private function storeToDatabase(array $data): void
    {
        try {
            AIRequest::create($data);
        } catch (\Exception $e) {
            Log::error('Failed to store AI request to database', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    private function updateDatabase(string $requestId, array $data): void
    {
        try {
            AIRequest::where('request_id', $requestId)->update($data);
        } catch (\Exception $e) {
            Log::error('Failed to update AI request in database', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    private function storeToFile(string $type, array $data): void
    {
        // Simplified file logging using default channel
        Log::info("AI {$type}", $data);
    }

    private function sanitizeData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveFields = $this->config['logging']['sensitive_fields'] ?? ['api_key'];
        
        return $this->recursiveSanitize($data, $sensitiveFields);
    }

    private function recursiveSanitize(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->recursiveSanitize($value, $sensitiveFields);
            }
        }

        return $data;
    }

    private function extractFileInfo(array $files): ?array
    {
        if (empty($files)) {
            return null;
        }

        return array_map(function ($file) {
            if (is_string($file)) {
                return [
                    'path' => basename($file),
                    'size' => file_exists($file) ? filesize($file) : null,
                    'type' => file_exists($file) ? mime_content_type($file) : null,
                ];
            }
            
            return $file;
        }, $files);
    }
}
