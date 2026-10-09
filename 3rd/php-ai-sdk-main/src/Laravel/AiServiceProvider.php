<?php

declare(strict_types=1);

namespace SnowmanNunu\Ai\Laravel;

use Illuminate\Support\ServiceProvider;
use SnowmanNunu\Ai\AiManager;
use SnowmanNunu\Ai\Laravel\Commands\AiTestCommand;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/ai.php', 'ai'
        );

        $this->app->singleton('ai', function ($app) {
            return new AiManager($app['config']['ai']);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/ai.php' => config_path('ai.php'),
            ], 'ai-config');

            $this->commands([
                AiTestCommand::class,
            ]);
        }
    }
}
