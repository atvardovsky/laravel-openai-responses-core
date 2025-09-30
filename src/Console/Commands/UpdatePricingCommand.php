<?php

namespace Atvardovsky\LaravelOpenAIResponses\Console\Commands;

use Illuminate\Console\Command;
use Atvardovsky\LaravelOpenAIResponses\Services\AIPricingService;

/**
 * Artisan command to check and update OpenAI model pricing
 */
class UpdatePricingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:update-pricing 
                            {--check : Only check if pricing is outdated}
                            {--force : Force update even if cache is fresh}
                            {--no-cache : Skip caching}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and update OpenAI model pricing from their website';

    /**
     * Execute the console command.
     */
    public function handle(AIPricingService $pricingService): int
    {
        $this->info('🔍 Checking OpenAI model pricing...');

        if ($this->option('check')) {
            return $this->checkPricing($pricingService);
        }

        if ($this->option('force')) {
            $pricingService->clearCache();
            $this->info('Cache cleared, fetching fresh data...');
        }

        try {
            $useCache = !$this->option('no-cache');
            $pricing = $pricingService->fetchPricing($useCache);
            
            if (empty($pricing)) {
                $this->error('❌ Failed to fetch pricing data');
                return 1;
            }

            $this->displayPricing($pricing);
            
            if ($this->confirm('Update configuration file with this pricing?', true)) {
                if ($pricingService->updateConfigPricing($pricing)) {
                    $this->info('✅ Configuration updated successfully!');
                    $this->warn('⚠️  Remember to clear your application cache: php artisan config:clear');
                } else {
                    $this->error('❌ Failed to update configuration file');
                    return 1;
                }
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Check if pricing is outdated
     */
    private function checkPricing(AIPricingService $pricingService): int
    {
        if ($pricingService->isPricingOutdated()) {
            $this->warn('⚠️  Pricing data is outdated (>24 hours old)');
            $this->info('Run without --check to update pricing');
            return 1;
        }

        $this->info('✅ Pricing data is up to date');
        
        $pricing = $pricingService->fetchPricing();
        $this->displayPricing($pricing);
        
        return 0;
    }

    /**
     * Display pricing in a formatted table
     */
    private function displayPricing(array $pricing): void
    {
        $this->newLine();
        $this->info('📊 Current Pricing (per 1K tokens):');
        
        $rows = [];
        foreach ($pricing as $model => $rates) {
            $rows[] = [
                $model,
                '$' . number_format($rates['prompt'], 4),
                '$' . number_format($rates['completion'], 4),
                '$' . number_format($rates['prompt'] * 1000 + $rates['completion'] * 1000, 2) . '/1M'
            ];
        }

        $this->table(
            ['Model', 'Input', 'Output', 'Per 1M (1K+1K)'],
            $rows
        );
    }
}
