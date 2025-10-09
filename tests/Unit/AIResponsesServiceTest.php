<?php

namespace Atvardovsky\LaravelOpenAIResponses\Tests\Unit;

use Atvardovsky\LaravelOpenAIResponses\Exceptions\AIResponseException;
use Atvardovsky\LaravelOpenAIResponses\Services\AIResponsesService;
use Atvardovsky\LaravelOpenAIResponses\Services\ToolRegistry;
use Illuminate\Contracts\Events\Dispatcher;
use Orchestra\Testbench\TestCase;

class AIResponsesServiceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Atvardovsky\LaravelOpenAIResponses\AIResponsesServiceProvider::class];
    }

    public function test_validates_configuration(): void
    {
        $this->expectException(AIResponseException::class);
        $this->expectExceptionMessage("Configuration field 'api_key' is required");
        
        new AIResponsesService(
            ['validation' => ['required_fields' => ['api_key']]],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );
    }

    public function test_validates_empty_messages(): void
    {
        $service = new AIResponsesService(
            [
                'api_key' => 'test-key',
                'base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
                'validation' => ['required_fields' => ['api_key', 'base_url', 'default_model']]
            ],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );

        $this->expectException(AIResponseException::class);
        $this->expectExceptionMessage('Messages array cannot be empty');
        
        $service->respond([]);
    }

    public function test_validates_message_structure(): void
    {
        $service = new AIResponsesService(
            [
                'api_key' => 'test-key',
                'base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
                'validation' => ['required_fields' => ['api_key', 'base_url', 'default_model']]
            ],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );

        $this->expectException(AIResponseException::class);
        $this->expectExceptionMessage('Message must have either content or tool_calls');
        
        $service->respond([
            ['role' => 'user'] // Missing content and tool_calls
        ]);
    }

    public function test_validates_message_role(): void
    {
        $service = new AIResponsesService(
            [
                'api_key' => 'test-key',
                'base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
                'validation' => ['required_fields' => ['api_key', 'base_url', 'default_model']]
            ],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );

        $this->expectException(AIResponseException::class);
        $this->expectExceptionMessage('Invalid message role: invalid');
        
        $service->respond([
            ['role' => 'invalid', 'content' => 'test']
        ]);
    }

    public function test_validates_temperature_range(): void
    {
        $service = new AIResponsesService(
            [
                'api_key' => 'test-key',
                'base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
                'validation' => ['required_fields' => ['api_key', 'base_url', 'default_model']]
            ],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );

        $this->expectException(AIResponseException::class);
        $this->expectExceptionMessage('Temperature must be between 0 and 2');
        
        $service->respond([
            ['role' => 'user', 'content' => 'test']
        ], ['temperature' => 3.0]);
    }

    public function test_validates_max_tokens(): void
    {
        $service = new AIResponsesService(
            [
                'api_key' => 'test-key',
                'base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
                'validation' => ['required_fields' => ['api_key', 'base_url', 'default_model']]
            ],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );

        $this->expectException(AIResponseException::class);
        $this->expectExceptionMessage('max_output_tokens must be greater than 0');
        
        $service->respond([
            ['role' => 'user', 'content' => 'test']
        ], ['max_tokens' => 0]);
    }

    public function test_withtools_returns_new_instance(): void
    {
        $service1 = new AIResponsesService(
            [
                'api_key' => 'test-key',
                'base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
                'validation' => ['required_fields' => ['api_key', 'base_url', 'default_model']]
            ],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );

        $service2 = $service1->withTools([]);
        
        $this->assertNotSame($service1, $service2);
        $this->assertInstanceOf(AIResponsesService::class, $service2);
    }

    public function test_withfiles_returns_new_instance(): void
    {
        $service1 = new AIResponsesService(
            [
                'api_key' => 'test-key',
                'base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
                'validation' => ['required_fields' => ['api_key', 'base_url', 'default_model']]
            ],
            new ToolRegistry(),
            $this->app->make(Dispatcher::class)
        );

        $service2 = $service1->withFiles([]);
        
        $this->assertNotSame($service1, $service2);
        $this->assertInstanceOf(AIResponsesService::class, $service2);
    }
}
