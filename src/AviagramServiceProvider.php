<?php

namespace Aviagram;

use Illuminate\Support\ServiceProvider;

class AviagramServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    protected bool $defer = false;

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/aviagram.php' => config_path('aviagram.php'),
        ], 'aviagram-config');
    }

    public function register(): void
    {
        $this->app->singleton(Services\AviagramGatewayService::class, function ($app) {
            return new Services\AviagramGatewayService();
        });

        $this->mergeConfigFrom(__DIR__ . '/config/aviagram.php', 'aviagram');
    }

    public function provides(): array
    {
        return [Services\AviagramGatewayService::class];
    }
}
