<?php

namespace Atvardovsky\LaravelOpenAIResponses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIToolCall extends Model
{
    protected $table = 'ai_tool_calls';

    protected $fillable = [
        'request_id',
        'tool_name',
        'arguments',
        'result',
        'status',
        'error_message',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the associated AI request (soft reference - no foreign key constraint)
     * 
     * @return BelongsTo
     */
    public function aiRequest(): BelongsTo
    {
        // Note: No foreign key constraint, so this relationship may return null
        return $this->belongsTo(AIRequest::class, 'request_id', 'request_id');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'error');
    }

    public function scopeByTool($query, string $toolName)
    {
        return $query->where('tool_name', $toolName);
    }

    public function getDurationSecondsAttribute(): float
    {
        return $this->duration_ms ? $this->duration_ms / 1000 : 0;
    }
}
