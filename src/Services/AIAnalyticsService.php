<?php

namespace Atvardovsky\LaravelOpenAIResponses\Services;

use Atvardovsky\LaravelOpenAIResponses\Models\AIMetric;
use Atvardovsky\LaravelOpenAIResponses\Models\AIRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AIAnalyticsService
{
    public function __construct(private array $config)
    {}

    public function aggregateDailyMetrics(?string $date = null): void
    {
        if (!($this->config['analytics']['enabled'] ?? true)) {
            return;
        }

        $targetDate = $date ? Carbon::parse($date) : Carbon::yesterday();
        
        $requests = AIRequest::whereBetween('created_at', [
            $targetDate->copy()->startOfDay(),
            $targetDate->copy()->endOfDay(),
        ])->get();
        
        $groupedByModel = $requests->groupBy('model');
        
        foreach ($groupedByModel as $model => $modelRequests) {
            $this->aggregateModelMetrics($targetDate->toDateString(), $model, $modelRequests);
        }
    }

    public function getUsageStats(string $from, string $to, ?string $model = null): array
    {
        $query = AIMetric::byDateRange($from, $to);
        
        if ($model) {
            $query->byModel($model);
        }

        $metrics = $query->get();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total_requests' => $metrics->sum('total_requests'),
            'successful_requests' => $metrics->sum('successful_requests'),
            'failed_requests' => $metrics->sum('failed_requests'),
            'streaming_requests' => $metrics->sum('streaming_requests'),
            'total_tokens' => $metrics->sum('total_tokens'),
            'total_cost' => $metrics->sum('total_cost'),
            'average_duration' => $metrics->avg('avg_duration_ms'),
            'success_rate' => $this->calculateSuccessRate($metrics),
            'daily_breakdown' => $metrics->groupBy('date')->map(function ($dayMetrics) {
                return [
                    'requests' => $dayMetrics->sum('total_requests'),
                    'tokens' => $dayMetrics->sum('total_tokens'),
                    'cost' => $dayMetrics->sum('total_cost'),
                ];
            }),
            'model_breakdown' => $metrics->groupBy('model')->map(function ($modelMetrics) {
                return [
                    'requests' => $modelMetrics->sum('total_requests'),
                    'tokens' => $modelMetrics->sum('total_tokens'),
                    'cost' => $modelMetrics->sum('total_cost'),
                ];
            }),
        ];
    }

    public function getTopTools(string $from, string $to, int $limit = 10): array
    {
        return DB::table('ai_tool_calls')
            ->join('ai_requests', 'ai_tool_calls.request_id', '=', 'ai_requests.request_id')
            ->whereBetween('ai_tool_calls.created_at', [$from, $to])
            ->select([
                'ai_tool_calls.tool_name',
                DB::raw('COUNT(*) as total_calls'),
                DB::raw('COUNT(CASE WHEN ai_tool_calls.status = "success" THEN 1 END) as successful_calls'),
                DB::raw('AVG(ai_tool_calls.duration_ms) as avg_duration'),
            ])
            ->groupBy('ai_tool_calls.tool_name')
            ->orderBy('total_calls', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function getCostAnalysis(string $from, string $to): array
    {
        $metrics = AIMetric::byDateRange($from, $to)->get();
        
        $totalCost = $metrics->sum('total_cost');
        $totalTokens = $metrics->sum('total_tokens');
        
        return [
            'total_cost' => $totalCost,
            'cost_per_token' => $totalTokens > 0 ? $totalCost / $totalTokens : 0,
            'cost_by_model' => $metrics->groupBy('model')->map(function ($modelMetrics) {
                return [
                    'cost' => $modelMetrics->sum('total_cost'),
                    'tokens' => $modelMetrics->sum('total_tokens'),
                    'requests' => $modelMetrics->sum('total_requests'),
                ];
            }),
            'daily_costs' => $metrics->groupBy('date')->map(fn($day) => $day->sum('total_cost')),
        ];
    }

    public function getPerformanceMetrics(string $from, string $to): array
    {
        $requests = AIRequest::byDateRange($from, $to)->get();
        
        return [
            'average_response_time' => $requests->avg('duration_ms'),
            'median_response_time' => $requests->median('duration_ms'),
            'p95_response_time' => $this->calculatePercentile($requests->pluck('duration_ms'), 95),
            'fastest_response' => $requests->min('duration_ms'),
            'slowest_response' => $requests->max('duration_ms'),
            'response_time_by_model' => $requests->groupBy('model')->map(function ($modelRequests) {
                return [
                    'average' => $modelRequests->avg('duration_ms'),
                    'median' => $modelRequests->median('duration_ms'),
                ];
            }),
        ];
    }

    public function cleanupOldData(): int
    {
        if (!($this->config['analytics']['cleanup_enabled'] ?? true)) {
            return 0;
        }

        $retentionDays = $this->config['analytics']['retention_days'] ?? 90;
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        $deletedRequests = AIRequest::where('created_at', '<', $cutoffDate)->delete();
        $deletedMetrics = AIMetric::where('date', '<', $cutoffDate)->delete();

        return $deletedRequests + $deletedMetrics;
    }

    private function aggregateModelMetrics(string $date, string $model, $requests): void
    {
        $successful = $requests->where('status', 'completed');
        $failed = $requests->where('status', 'failed');
        $streaming = $requests->where('status', 'streaming');

        $data = [
            'date' => $date,
            'model' => $model,
            'total_requests' => $requests->count(),
            'successful_requests' => $successful->count(),
            'failed_requests' => $failed->count(),
            'streaming_requests' => $streaming->count(),
            'total_prompt_tokens' => $requests->sum('prompt_tokens'),
            'total_completion_tokens' => $requests->sum('completion_tokens'),
            'total_tokens' => $requests->sum('total_tokens'),
            'total_cost' => $requests->sum('estimated_cost'),
            'avg_duration_ms' => $requests->avg('duration_ms') ?: 0,
            'tools_called' => $requests->sum(fn($r) => $r->toolCalls->count()),
        ];

        AIMetric::updateOrCreate(
            ['date' => $date, 'model' => $model],
            $data
        );
    }

    private function calculateSuccessRate($metrics): float
    {
        $totalRequests = $metrics->sum('total_requests');
        $successfulRequests = $metrics->sum('successful_requests');
        
        return $totalRequests > 0 ? ($successfulRequests / $totalRequests) * 100 : 0;
    }

    private function calculatePercentile($values, $percentile): float
    {
        $sorted = $values->filter()->sort()->values();
        $count = $sorted->count();
        
        if ($count === 0) {
            return 0;
        }
        
        $index = ($percentile / 100) * ($count - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower === $upper) {
            return $sorted[$lower] ?? 0;
        }
        
        $weight = $index - $lower;
        return ($sorted[$lower] ?? 0) * (1 - $weight) + ($sorted[$upper] ?? 0) * $weight;
    }
}
