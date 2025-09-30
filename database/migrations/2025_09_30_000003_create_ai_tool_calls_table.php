<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->string('request_id');
            $table->string('tool_name');
            $table->json('arguments');
            $table->json('result')->nullable();
            $table->string('status')->default('pending'); // pending, success, error
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
            
            $table->index(['request_id']);
            $table->index(['tool_name']);
            $table->index(['created_at']);
            $table->foreign('request_id')->references('request_id')->on('ai_requests')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};
