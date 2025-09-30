<?php

namespace Atvardovsky\LaravelOpenAIResponses;

use Atvardovsky\LaravelOpenAIResponses\Console\Commands\AggregateAIMetricsCommand;
use Atvardovsky\LaravelOpenAIResponses\Console\Commands\AIAnalyticsCommand;
use Atvardovsky\LaravelOpenAIResponses\Console\Commands\CleanupAIDataCommand;
use Atvardovsky\LaravelOpenAIResponses\Console\Commands\UpdatePricingCommand;
use Atvardovsky\LaravelOpenAIResponses\Events\AfterResponse;
use Atvardovsky\LaravelOpenAIResponses\Events\BeforeRequest;
use Atvardovsky\LaravelOpenAIResponses\Events\ToolCalled;
use Atvardovsky\LaravelOpenAIResponses\Listeners\LogAIRequestListener;
use Atvardovsky\LaravelOpenAIResponses\Listeners\LogToolCallListener;
use Atvardovsky\LaravelOpenAIResponses\Services\AIAnalyticsService;
use Atvardovsky\LaravelOpenAIResponses\Services\AILoggingService;
use Atvardovsky\LaravelOpenAIResponses\Services\AIPricingService;
use Atvardovsky\LaravelOpenAIResponses\Services\AIResponsesService;
use Atvardovsky\LaravelOpenAIResponses\Services\ToolRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AIResponsesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai_responses.php', 'ai_responses');

        $this->app->singleton(ToolRegistry::class, function ($app) {
            return new ToolRegistry($app['config']['ai_responses.tools']);
        });

        $this->app->singleton(AILoggingService::class, function ($app) {
            return new AILoggingService($app['config']['ai_responses']);
        });

        $this->app->singleton(AIAnalyticsService::class, function ($app) {
            return new AIAnalyticsService($app['config']['ai_responses']);
        });

        $this->app->singleton(AIPricingService::class, function ($app) {
            return new AIPricingService();
        });

        $this->app->singleton(AIResponsesService::class, function ($app) {
            return new AIResponsesService(
                $app['config']['ai_responses'],
                $app[ToolRegistry::class],
                $app['events']
            );
        });

        $this->app->alias(AIResponsesService::class, 'ai-responses');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai_responses.php' => config_path('ai_responses.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'migrations');

            $this->commands([
                AggregateAIMetricsCommand::class,
                AIAnalyticsCommand::class,
                CleanupAIDataCommand::class,
                UpdatePricingCommand::class,
            ]);
        }

        // Register event listeners
        Event::subscribe(LogAIRequestListener::class);
        Event::listen(ToolCalled::class, LogToolCallListener::class);

        // Load migrations if not published
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function provides(): array
    {
        return [
            AIResponsesService::class,
            ToolRegistry::class,
            AILoggingService::class,
            AIAnalyticsService::class,
            AIPricingService::class,
            'ai-responses',
        ];
    }
}
