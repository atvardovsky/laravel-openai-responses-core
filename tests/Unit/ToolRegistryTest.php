<?php

namespace Atvardovsky\LaravelOpenAIResponses\Tests\Unit;

use Atvardovsky\LaravelOpenAIResponses\Events\ToolCalled;
use Atvardovsky\LaravelOpenAIResponses\Services\ToolRegistry;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class ToolRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Atvardovsky\LaravelOpenAIResponses\AIResponsesServiceProvider::class];
    }

    public function test_tool_registration(): void
    {
        $registry = new ToolRegistry();
        
        $registry->register('test_tool', [
            'name' => 'test_tool',
            'description' => 'A test tool'
        ], function ($args) {
            return 'result';
        });

        $this->assertTrue($registry->isRegistered('test_tool'));
        $this->assertContains('test_tool', $registry->getRegisteredTools());
    }

    public function test_tool_execution(): void
    {
        Event::fake();
        
        $registry = new ToolRegistry();
        
        $registry->register('math_tool', [
            'name' => 'math_tool',
            'description' => 'Does math'
        ], function ($args) {
            return $args['a'] + $args['b'];
        });

        $result = $registry->call('math_tool', ['a' => 2, 'b' => 3]);
        
        $this->assertEquals(5, $result);
        Event::assertDispatched(ToolCalled::class);
    }

    public function test_tool_not_found_exception(): void
    {
        $registry = new ToolRegistry();
        
        $this->expectException(\InvalidArgumentException::class);
        $registry->call('nonexistent_tool', []);
    }

    public function test_max_tools_limit(): void
    {
        $registry = new ToolRegistry(['max_registered' => 1]);
        
        $registry->register('tool1', [], function() {});
        
        $this->expectException(\InvalidArgumentException::class);
        $registry->register('tool2', [], function() {});
    }

    public function test_get_schema(): void
    {
        $registry = new ToolRegistry();
        $schema = ['name' => 'test', 'description' => 'Test tool'];
        
        $registry->register('test', $schema, function() {});
        
        $this->assertEquals($schema, $registry->getSchema('test'));
        $this->assertNull($registry->getSchema('nonexistent'));
    }
}
