<?php

namespace Atvardovsky\LaravelOpenAIResponses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model representing an AI request record in the database.
 * 
 * Stores comprehensive information about each AI API request including messages,
 * options, response data, performance metrics, and error details. Used for
 * analytics, billing, debugging, and audit trails.
 *
 * @package Atvardovsky\LaravelOpenAIResponses\Models
 * @version 1.0.0
 * @since 2025-09-30
 * 
 * @property int $id Primary key
 * @property string $request_id Unique request identifier (UUID)
 * @property string $model OpenAI model used (e.g., 'gpt-4o')
 * @property array $messages Conversation messages array
 * @property array|null $options Request options (temperature, max_tokens, etc.)
 * @property array|null $tools Tools available during request
 * @property array|null $files Files attached to request
 * @property array|null $response Complete OpenAI response
 * @property string $status Request status: pending, completed, failed, streaming
 * @property string|null $error_message Error description if failed
 * @property int|null $prompt_tokens Tokens used in prompt
 * @property int|null $completion_tokens Tokens used in completion
 * @property int|null $total_tokens Total tokens consumed
 * @property float|null $estimated_cost Calculated cost in USD
 * @property int|null $duration_ms Request duration in milliseconds
 * @property string|null $ip_address Client IP address
 * @property string|null $user_agent Client user agent
 * @property int|null $user_id Associated user ID (if authenticated)
 * @property \Carbon\Carbon $created_at Request timestamp
 * @property \Carbon\Carbon $updated_at Last updated timestamp
 * 
 * @method static \Illuminate\Database\Eloquent\Builder successful() Scope for completed requests
 * @method static \Illuminate\Database\Eloquent\Builder failed() Scope for failed requests
 * @method static \Illuminate\Database\Eloquent\Builder streaming() Scope for streaming requests
 * @method static \Illuminate\Database\Eloquent\Builder byModel(string $model) Scope by model
 * @method static \Illuminate\Database\Eloquent\Builder byDateRange($from, $to) Scope by date range
 */
class AIRequest extends Model
{
    protected $fillable = [
        'request_id',
        'model',
        'messages',
        'options',
        'tools',
        'files',
        'response',
        'status',
        'error_message',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost',
        'duration_ms',
        'ip_address',
        'user_agent',
        'user_id',
    ];

    protected $casts = [
        'messages' => 'array',
        'options' => 'array',
        'tools' => 'array',
        'files' => 'array',
        'response' => 'array',
        'estimated_cost' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AIToolCall::class, 'request_id', 'request_id');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeStreaming($query)
    {
        return $query->where('status', 'streaming');
    }

    public function scopeByModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    public function scopeByDateRange($query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function getTotalCostAttribute(): float
    {
        return $this->estimated_cost ?? 0;
    }

    public function getDurationSecondsAttribute(): float
    {
        return $this->duration_ms ? $this->duration_ms / 1000 : 0;
    }
}
