<?php

namespace Atvardovsky\LaravelOpenAIResponses\Console\Commands;

use Atvardovsky\LaravelOpenAIResponses\Services\AIAnalyticsService;
use Illuminate\Console\Command;

class AIAnalyticsCommand extends Command
{
    protected $signature = 'ai:analytics 
                            {from : Start date (Y-m-d)}
                            {to : End date (Y-m-d)}
                            {--model= : Filter by model}
                            {--export= : Export to file (csv|json)}';
    
    protected $description = 'Show AI usage analytics for the specified date range';

    public function handle(AIAnalyticsService $analyticsService): int
    {
        $from = $this->argument('from');
        $to = $this->argument('to');
        $model = $this->option('model');
        $export = $this->option('export');

        $this->info("📊 AI Analytics Report: {$from} to {$to}");
        
        if ($model) {
            $this->line("🎯 Model: {$model}");
        }

        $stats = $analyticsService->getUsageStats($from, $to, $model);
        
        // Display overview
        $this->newLine();
        $this->info('📈 Usage Overview');
        $this->table(['Metric', 'Value'], [
            ['Total Requests', number_format($stats['total_requests'])],
            ['Successful Requests', number_format($stats['successful_requests'])],
            ['Failed Requests', number_format($stats['failed_requests'])],
            ['Streaming Requests', number_format($stats['streaming_requests'])],
            ['Success Rate', number_format($stats['success_rate'], 2) . '%'],
            ['Total Tokens', number_format($stats['total_tokens'])],
            ['Total Cost', '$' . number_format($stats['total_cost'], 4)],
            ['Avg Duration', number_format($stats['average_duration'], 0) . 'ms'],
        ]);

        // Model breakdown
        if ($stats['model_breakdown']->isNotEmpty()) {
            $this->newLine();
            $this->info('🤖 Model Breakdown');
            $modelData = $stats['model_breakdown']->map(function ($data, $model) {
                return [
                    'Model' => $model,
                    'Requests' => number_format($data['requests']),
                    'Tokens' => number_format($data['tokens']),
                    'Cost' => '$' . number_format($data['cost'], 4),
                ];
            })->values();
            
            $this->table(['Model', 'Requests', 'Tokens', 'Cost'], $modelData);
        }

        // Top tools
        $this->newLine();
        $this->info('🔧 Top Tools');
        $topTools = $analyticsService->getTopTools($from, $to);
        
        if (!empty($topTools)) {
            $this->table(['Tool', 'Calls', 'Success Rate', 'Avg Duration'], 
                array_map(function ($tool) {
                    $successRate = $tool->total_calls > 0 
                        ? ($tool->successful_calls / $tool->total_calls) * 100 
                        : 0;
                    
                    return [
                        $tool->tool_name,
                        number_format($tool->total_calls),
                        number_format($successRate, 1) . '%',
                        number_format($tool->avg_duration, 0) . 'ms',
                    ];
                }, $topTools)
            );
        } else {
            $this->line('No tool usage found.');
        }

        // Export if requested
        if ($export) {
            $this->exportData($stats, $export, $from, $to, $model);
        }

        return 0;
    }

    private function exportData(array $stats, string $format, string $from, string $to, ?string $model): void
    {
        $filename = "ai-analytics-{$from}-to-{$to}" . ($model ? "-{$model}" : '') . ".{$format}";
        
        match ($format) {
            'json' => file_put_contents($filename, json_encode($stats, JSON_PRETTY_PRINT)),
            'csv' => $this->exportToCsv($stats, $filename),
            default => $this->error("Unsupported export format: {$format}")
        };

        $this->info("📄 Exported to: {$filename}");
    }

    private function exportToCsv(array $stats, string $filename): void
    {
        $file = fopen($filename, 'w');
        
        fputcsv($file, ['Metric', 'Value']);
        fputcsv($file, ['Total Requests', $stats['total_requests']]);
        fputcsv($file, ['Successful Requests', $stats['successful_requests']]);
        fputcsv($file, ['Failed Requests', $stats['failed_requests']]);
        fputcsv($file, ['Success Rate %', $stats['success_rate']]);
        fputcsv($file, ['Total Tokens', $stats['total_tokens']]);
        fputcsv($file, ['Total Cost', $stats['total_cost']]);
        fputcsv($file, ['Average Duration ms', $stats['average_duration']]);
        
        fclose($file);
    }
}
