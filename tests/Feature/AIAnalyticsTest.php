<?php

namespace Atvardovsky\LaravelOpenAIResponses\Tests\Feature;

use Atvardovsky\LaravelOpenAIResponses\Models\AIMetric;
use Atvardovsky\LaravelOpenAIResponses\Models\AIRequest;
use Atvardovsky\LaravelOpenAIResponses\Services\AIAnalyticsService;
use Carbon\Carbon;
use Orchestra\Testbench\TestCase;

class AIAnalyticsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Atvardovsky\LaravelOpenAIResponses\AIResponsesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
    }

    public function test_aggregate_daily_metrics(): void
    {
        // Use fixed date for consistent testing (September 2025)
        $testDate = Carbon::parse('2025-09-29');
        
        $req1 = new AIRequest([
            'request_id' => 'req1',
            'model' => 'gpt-4o',
            'messages' => [],
            'status' => 'completed',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'estimated_cost' => 0.01,
            'duration_ms' => 1000,
        ]);
        $req1->created_at = $testDate;
        $req1->updated_at = $testDate;
        $req1->save();

        $req2 = new AIRequest([
            'request_id' => 'req2',
            'model' => 'gpt-4o',
            'messages' => [],
            'status' => 'failed',
        ]);
        $req2->created_at = $testDate;
        $req2->updated_at = $testDate;
        $req2->save();

        $analytics = new AIAnalyticsService(['analytics' => ['enabled' => true]]);
        $analytics->aggregateDailyMetrics($testDate->toDateString());

        $metric = AIMetric::where('date', $testDate)
                         ->where('model', 'gpt-4o')
                         ->first();

        $this->assertNotNull($metric);
        $this->assertEquals(2, $metric->total_requests);
        $this->assertEquals(1, $metric->successful_requests);
        $this->assertEquals(1, $metric->failed_requests);
        $this->assertEquals(150, $metric->total_tokens);
        $this->assertEquals(0.01, $metric->total_cost);
    }

    public function test_usage_stats(): void
    {
        $testDate = Carbon::parse('2025-09-29');
        
        $metric = new AIMetric([
            'date' => $testDate,
            'model' => 'gpt-4o',
            'total_requests' => 10,
            'successful_requests' => 9,
            'failed_requests' => 1,
            'total_tokens' => 1000,
            'total_cost' => 0.10,
        ]);
        $metric->save();

        $analytics = new AIAnalyticsService(['analytics' => ['enabled' => true]]);
        $stats = $analytics->getUsageStats($testDate, $testDate);

        $this->assertEquals(10, $stats['total_requests']);
        $this->assertEquals(9, $stats['successful_requests']);
        $this->assertEquals(90, $stats['success_rate']);
        $this->assertEquals(1000, $stats['total_tokens']);
        $this->assertEquals(0.10, $stats['total_cost']);
    }

    public function test_cleanup_old_data(): void
    {
        $oldDate = Carbon::parse('2025-06-01'); // 4 months old from Sept 2025
        
        $oldReq = new AIRequest([
            'request_id' => 'old_req',
            'model' => 'gpt-4o',
            'messages' => [],
        ]);
        $oldReq->created_at = $oldDate;
        $oldReq->updated_at = $oldDate;
        $oldReq->save();

        $newReq = new AIRequest([
            'request_id' => 'new_req',
            'model' => 'gpt-4o',
            'messages' => [],
        ]);
        $newReq->created_at = Carbon::parse('2025-09-29');
        $newReq->updated_at = Carbon::parse('2025-09-29');
        $newReq->save();

        $analytics = new AIAnalyticsService([
            'analytics' => [
                'enabled' => true,
                'cleanup_enabled' => true,
                'retention_days' => 90,
            ]
        ]);

        $deleted = $analytics->cleanupOldData();

        $this->assertGreaterThan(0, $deleted);
        $this->assertNull(AIRequest::where('request_id', 'old_req')->first());
        $this->assertNotNull(AIRequest::where('request_id', 'new_req')->first());
    }
}
