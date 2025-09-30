<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->string('model');
            $table->json('messages');
            $table->json('options')->nullable();
            $table->json('tools')->nullable();
            $table->json('files')->nullable();
            $table->json('response')->nullable();
            $table->string('status')->default('pending'); // pending, completed, failed, streaming
            $table->text('error_message')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            
            $table->index(['created_at']);
            $table->index(['status']);
            $table->index(['model']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
