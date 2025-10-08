<?php

namespace Atvardovsky\LaravelOpenAIResponses\Services;

use Atvardovsky\LaravelOpenAIResponses\Events\AfterResponse;
use Atvardovsky\LaravelOpenAIResponses\Events\BeforeRequest;
use Atvardovsky\LaravelOpenAIResponses\Events\RateLimited;
use Atvardovsky\LaravelOpenAIResponses\Exceptions\AIResponseException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AIResponsesServiceImproved
{
    public function __construct(
        private array $config,
        private ToolRegistry $toolRegistry,
        private Dispatcher $events
    ) {
        $this->validateConfiguration();
    }

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
            throw new AIResponseException("AI request failed: " . $e->getMessage(), 0, $e);
        }
    }

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

    public function withTools(array $tools): self
    {
        // Return new instance to avoid state mutation
        $clone = clone $this;
        $clone->validateTools($tools);
        return $clone;
    }

    public function withFiles(array $files): self
    {
        // Return new instance to avoid state mutation
        $clone = clone $this;
        $clone->validateFiles($files);
        return $clone;
    }

    private function buildRequestContext(array $messages, array $options): array
    {
        $this->validateMessages($messages);
        $this->validateOptions($options);
        
        $tools = $options['tools'] ?? [];
        $files = $options['files'] ?? [];
        
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

    private function validateConfiguration(): void
    {
        if (empty($this->config['api_key'])) {
            throw new AIResponseException('OpenAI API key is required');
        }
        
        if (empty($this->config['base_url'])) {
            throw new AIResponseException('OpenAI base URL is required');
        }
    }

    private function validateMessages(array $messages): void
    {
        if (empty($messages)) {
            throw new AIResponseException('Messages array cannot be empty');
        }

        foreach ($messages as $message) {
            if (!isset($message['role'], $message['content'])) {
                throw new AIResponseException('Each message must have role and content');
            }
            
            if (!in_array($message['role'], ['system', 'user', 'assistant', 'tool'])) {
                throw new AIResponseException('Invalid message role: ' . $message['role']);
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
        foreach ($files as $file) {
            if (is_string($file)) {
                if (!file_exists($file)) {
                    throw new AIResponseException("File not found: {$file}");
                }
                
                $size = filesize($file);
                $maxSize = $this->config['files']['max_size'] ?? 20 * 1024 * 1024; // 20MB default
                
                if ($size > $maxSize) {
                    throw new AIResponseException("File too large: {$file} ({$size} bytes)");
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
        $model = $options['model'] ?? $this->config['default_model'];
        
        if (empty($model)) {
            throw new AIResponseException('Model is required');
        }
        
        return $model;
    }

    private function getMaxTokens(array $options): int
    {
        return $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4000;
    }

    private function getTemperature(array $options): float
    {
        return $options['temperature'] ?? $this->config['temperature'] ?? 0.7;
    }

    // ... rest of methods remain the same but with improved error handling
}
