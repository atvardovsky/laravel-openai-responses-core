<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add composite indexes for better query performance
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->index(['created_at', 'model'], 'ai_requests_date_model_idx');
            $table->index(['status', 'created_at'], 'ai_requests_status_date_idx');
            $table->index(['user_id', 'created_at'], 'ai_requests_user_date_idx');
            $table->index('estimated_cost', 'ai_requests_cost_idx');
        });

        Schema::table('ai_metrics', function (Blueprint $table) {
            $table->index(['date', 'total_requests'], 'ai_metrics_date_requests_idx');
            $table->index('total_cost', 'ai_metrics_cost_idx');
        });

        Schema::table('ai_tool_calls', function (Blueprint $table) {
            $table->index(['tool_name', 'status'], 'ai_tool_calls_name_status_idx');
            $table->index(['created_at', 'status'], 'ai_tool_calls_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropIndex('ai_requests_date_model_idx');
            $table->dropIndex('ai_requests_status_date_idx');
            $table->dropIndex('ai_requests_user_date_idx');
            $table->dropIndex('ai_requests_cost_idx');
        });

        Schema::table('ai_metrics', function (Blueprint $table) {
            $table->dropIndex('ai_metrics_date_requests_idx');
            $table->dropIndex('ai_metrics_cost_idx');
        });

        Schema::table('ai_tool_calls', function (Blueprint $table) {
            $table->dropIndex('ai_tool_calls_name_status_idx');
            $table->dropIndex('ai_tool_calls_date_status_idx');
        });
    }
};
