<?php

namespace Atvardovsky\LaravelOpenAIResponses\Tests\Feature;

use Atvardovsky\LaravelOpenAIResponses\Events\AfterResponse;
use Atvardovsky\LaravelOpenAIResponses\Events\BeforeRequest;
use Atvardovsky\LaravelOpenAIResponses\Facades\AIResponses;
use Atvardovsky\LaravelOpenAIResponses\Services\AIResponsesService;
use Atvardovsky\LaravelOpenAIResponses\Services\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class AIResponsesServiceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Atvardovsky\LaravelOpenAIResponses\AIResponsesServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['AIResponses' => \Atvardovsky\LaravelOpenAIResponses\Facades\AIResponses::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai_responses.api_key', 'test-key');
        $app['config']->set('ai_responses.base_url', 'https://api.openai.com/v1');
    }

    public function test_service_is_registered(): void
    {
        $this->assertInstanceOf(AIResponsesService::class, $this->app->make(AIResponsesService::class));
        $this->assertInstanceOf(ToolRegistry::class, $this->app->make(ToolRegistry::class));
    }

    public function test_respond_method_works(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Hello world!']]
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15
                ]
            ])
        ]);

        Event::fake();

        $response = AIResponses::respond([
            ['role' => 'user', 'content' => 'Hello']
        ]);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('choices', $response);

        Event::assertDispatched(BeforeRequest::class);
        Event::assertDispatched(AfterResponse::class);
    }

    public function test_stream_method_works(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response("data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: [DONE]\n\n")
        ]);

        Event::fake();

        $chunks = [];
        foreach (AIResponses::stream([['role' => 'user', 'content' => 'Hello']]) as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertNotEmpty($chunks);
        Event::assertDispatched(BeforeRequest::class);
    }

    public function test_with_tools_method(): void
    {
        $service = AIResponses::withTools(['test_tool']);
        $this->assertInstanceOf(AIResponsesService::class, $service);
    }

    public function test_with_files_method(): void
    {
        $service = AIResponses::withFiles(['test.jpg']);
        $this->assertInstanceOf(AIResponsesService::class, $service);
    }

    public function test_tool_registry_registration(): void
    {
        $registry = $this->app->make(ToolRegistry::class);
        
        $registry->register('test_tool', [
            'name' => 'test_tool',
            'description' => 'A test tool'
        ], function ($args) {
            return 'test result';
        });

        $this->assertTrue($registry->isRegistered('test_tool'));
        $this->assertEquals('test result', $registry->call('test_tool', []));
    }

    public function test_metrics_can_be_disabled(): void
    {
        $this->app['config']->set('ai_responses.metrics.enabled', false);
        
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Hello world!']]
                ]
            ])
        ]);

        $response = AIResponses::respond([
            ['role' => 'user', 'content' => 'Hello']
        ]);

        $this->assertIsArray($response);
        // Metrics should still be disabled and API should work
    }
}
