<?php

namespace Atvardovsky\LaravelOpenAIResponses\Models;

use Illuminate\Database\Eloquent\Model;

class AIMetric extends Model
{
    protected $fillable = [
        'date',
        'model',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'streaming_requests',
        'total_prompt_tokens',
        'total_completion_tokens',
        'total_tokens',
        'total_cost',
        'avg_duration_ms',
        'tools_called',
    ];

    protected $casts = [
        'date' => 'date',
        'total_cost' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeByDateRange($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    public function getSuccessRateAttribute(): float
    {
        return $this->total_requests > 0 
            ? ($this->successful_requests / $this->total_requests) * 100 
            : 0;
    }

    public function getAverageCostPerRequestAttribute(): float
    {
        return $this->total_requests > 0 
            ? $this->total_cost / $this->total_requests 
            : 0;
    }

    public function getAverageTokensPerRequestAttribute(): float
    {
        return $this->total_requests > 0 
            ? $this->total_tokens / $this->total_requests 
            : 0;
    }
}
