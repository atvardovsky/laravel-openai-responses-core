<?php

namespace Atvardovsky\LaravelOpenAIResponses\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Atvardovsky\LaravelOpenAIResponses\Exceptions\AIResponseException;

/**
 * Service for fetching and managing OpenAI model pricing
 * 
 * @api
 */
class AIPricingService
{
    /**
     * OpenAI pricing page URL
     */
    private const PRICING_URL = 'https://openai.com/api/pricing/';
    
    /**
     * Cache key for pricing data
     */
    private const CACHE_KEY = 'openai_pricing_data';
    
    /**
     * Cache duration in seconds (24 hours)
     */
    private const CACHE_DURATION = 86400;

    /**
     * Fetch current pricing from OpenAI
     * 
     * @param bool $useCache Whether to use cached data
     * @return array Model pricing data
     * @throws AIResponseException
     * 
     * @example
     * ```php
     * $pricing = app(AIPricingService::class)->fetchPricing();
     * // Returns: ['gpt-4o' => ['prompt' => 0.004, 'completion' => 0.016], ...]
     * ```
     */
    public function fetchPricing(bool $useCache = true): array
    {
        if ($useCache && $cached = Cache::get(self::CACHE_KEY)) {
            return $cached;
        }

        try {
            $pricing = $this->scrapePricingPage();
            
            if (!empty($pricing)) {
                Cache::put(self::CACHE_KEY, $pricing, self::CACHE_DURATION);
                Log::info('AIPricingService: Successfully fetched pricing data', [
                    'models_count' => count($pricing),
                    'models' => array_keys($pricing)
                ]);
            }
            
            return $pricing;
            
        } catch (\Exception $e) {
            Log::error('AIPricingService: Failed to fetch pricing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return cached data if available, otherwise fallback
            return Cache::get(self::CACHE_KEY) ?? $this->getFallbackPricing();
        }
    }

    /**
     * Get pricing for a specific model
     * 
     * @param string $model Model name
     * @return array|null Pricing data or null if not found
     * 
     * @example
     * ```php
     * $gptPricing = $service->getModelPricing('gpt-4o');
     * // Returns: ['prompt' => 0.004, 'completion' => 0.016]
     * ```
     */
    public function getModelPricing(string $model): ?array
    {
        $pricing = $this->fetchPricing();
        return $pricing[$model] ?? null;
    }

    /**
     * Clear pricing cache
     * 
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Log::info('AIPricingService: Cache cleared');
    }

    /**
     * Check if pricing data is outdated
     * 
     * @param int $maxAgeHours Maximum age in hours
     * @return bool True if data is outdated
     */
    public function isPricingOutdated(int $maxAgeHours = 24): bool
    {
        $lastUpdated = Cache::get(self::CACHE_KEY . '_timestamp');
        
        if (!$lastUpdated) {
            return true;
        }
        
        return (time() - $lastUpdated) > ($maxAgeHours * 3600);
    }

    /**
     * Scrape pricing data from OpenAI website
     * 
     * @return array Pricing data
     * @throws AIResponseException
     */
    private function scrapePricingPage(): array
    {
        $response = Http::timeout(30)->get(self::PRICING_URL);
        
        if (!$response->successful()) {
            throw new AIResponseException('Failed to fetch pricing page: ' . $response->status());
        }
        
        $html = $response->body();
        $pricing = [];
        
        // Parse pricing data from HTML
        // This is a simplified parser - in production you might want to use DOMDocument
        if (preg_match_all('/GPT-4o[^"]*"[^>]*>.*?\$(\d+(?:\.\d+)?)[^>]*1M[^>]*>.*?\$(\d+(?:\.\d+)?)[^>]*1M/s', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (strpos($match[0], 'mini') !== false) {
                    $pricing['gpt-4o-mini'] = [
                        'prompt' => floatval($match[1]) / 1000,
                        'completion' => floatval($match[2]) / 1000,
                    ];
                } else {
                    $pricing['gpt-4o'] = [
                        'prompt' => floatval($match[1]) / 1000,
                        'completion' => floatval($match[2]) / 1000,
                    ];
                }
            }
        }
        
        // Add timestamp
        Cache::put(self::CACHE_KEY . '_timestamp', time(), self::CACHE_DURATION);
        
        return $pricing ?: $this->getFallbackPricing();
    }

    /**
     * Get fallback pricing when scraping fails
     * 
     * @return array Default pricing data
     */
    private function getFallbackPricing(): array
    {
        return [
            'gpt-4o' => [
                'prompt' => 0.004,
                'completion' => 0.016,
            ],
            'gpt-4o-mini' => [
                'prompt' => 0.0006,
                'completion' => 0.0024,
            ],
            'gpt-4-turbo' => [
                'prompt' => 0.01,
                'completion' => 0.03,
            ],
        ];
    }

    /**
     * Update configuration file with new pricing
     * 
     * @param array $pricing New pricing data
     * @return bool Success status
     */
    public function updateConfigPricing(array $pricing): bool
    {
        $configPath = config_path('ai_responses.php');
        
        if (!file_exists($configPath)) {
            Log::error('AIPricingService: Config file not found', ['path' => $configPath]);
            return false;
        }
        
        try {
            $content = file_get_contents($configPath);
            
            // Simple replacement - in production you might want more sophisticated parsing
            $newPricingSection = "    'pricing' => " . var_export($pricing, true) . ",";
            $pattern = "/'pricing'\s*=>\s*\[[^\]]+\],/s";
            
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $newPricingSection, $content);
                file_put_contents($configPath, $content);
                
                Log::info('AIPricingService: Config updated with new pricing');
                return true;
            }
            
        } catch (\Exception $e) {
            Log::error('AIPricingService: Failed to update config', [
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }
}
