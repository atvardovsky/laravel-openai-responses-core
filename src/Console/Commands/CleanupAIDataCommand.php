<?php

namespace Atvardovsky\LaravelOpenAIResponses\Console\Commands;

use Atvardovsky\LaravelOpenAIResponses\Services\AIAnalyticsService;
use Illuminate\Console\Command;

class CleanupAIDataCommand extends Command
{
    protected $signature = 'ai:cleanup {--force}';
    protected $description = 'Clean up old AI request data based on retention policy';

    public function handle(AIAnalyticsService $analyticsService): int
    {
        if (!$this->option('force') && !$this->confirm('This will permanently delete old AI request data. Continue?')) {
            $this->info('Cleanup cancelled.');
            return 0;
        }

        $this->info('🧹 Cleaning up old AI data...');
        
        try {
            $deletedRecords = $analyticsService->cleanupOldData();
            
            if ($deletedRecords > 0) {
                $this->info("✅ Cleaned up {$deletedRecords} old records");
            } else {
                $this->info('ℹ️  No old records found to clean up');
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Cleanup failed: ' . $e->getMessage());
            return 1;
        }
    }
}
