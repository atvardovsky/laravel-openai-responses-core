<?php

namespace Atvardovsky\LaravelOpenAIResponses\Console\Commands;

use Atvardovsky\LaravelOpenAIResponses\Services\AIAnalyticsService;
use Illuminate\Console\Command;

class AggregateAIMetricsCommand extends Command
{
    protected $signature = 'ai:aggregate-metrics {date?}';
    protected $description = 'Aggregate AI request metrics for the specified date';

    public function handle(AIAnalyticsService $analyticsService): int
    {
        $date = $this->argument('date');
        
        $this->info('Aggregating AI metrics' . ($date ? " for {$date}" : ' for yesterday'));
        
        try {
            $analyticsService->aggregateDailyMetrics($date);
            $this->info('✅ Metrics aggregated successfully');
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to aggregate metrics: ' . $e->getMessage());
            return 1;
        }
    }
}
