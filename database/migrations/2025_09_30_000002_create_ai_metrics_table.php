<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('model');
            $table->integer('total_requests')->default(0);
            $table->integer('successful_requests')->default(0);
            $table->integer('failed_requests')->default(0);
            $table->integer('streaming_requests')->default(0);
            $table->bigInteger('total_prompt_tokens')->default(0);
            $table->bigInteger('total_completion_tokens')->default(0);
            $table->bigInteger('total_tokens')->default(0);
            $table->decimal('total_cost', 12, 6)->default(0);
            $table->integer('avg_duration_ms')->default(0);
            $table->integer('tools_called')->default(0);
            $table->timestamps();
            
            $table->unique(['date', 'model']);
            $table->index(['date']);
            $table->index(['model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_metrics');
    }
};
