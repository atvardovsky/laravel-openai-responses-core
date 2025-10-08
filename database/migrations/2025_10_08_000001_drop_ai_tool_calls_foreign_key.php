<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Removes foreign key constraint from ai_tool_calls table for existing installations.
     * This constraint was too restrictive as tool calls may be logged before/after 
     * request records due to async event handling.
     * 
     * For new installations (v1.0.1+), the constraint is never created in the first place.
     */
    public function up(): void
    {
        // Only needed for upgrading from v1.0.0 where foreign key existed
        // Fresh installations won't have the foreign key, so this is a no-op for them
        if (Schema::hasTable('ai_tool_calls')) {
            Schema::table('ai_tool_calls', function (Blueprint $table) {
                try {
                    $table->dropForeign(['request_id']);
                } catch (\Exception $e) {
                    // Foreign key doesn't exist - this is expected for new installations
                    // or if it was already dropped
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_tool_calls', function (Blueprint $table) {
            // Restore the foreign key constraint
            $table->foreign('request_id')
                ->references('request_id')
                ->on('ai_requests')
                ->onDelete('cascade');
        });
    }
};
