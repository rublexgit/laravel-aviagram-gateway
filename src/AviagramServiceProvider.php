<?php

namespace Aviagram;

use Illuminate\Support\ServiceProvider;
use Rublex\CoreGateway\Support\GatewayDriverRegistry;

class AviagramServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    protected bool $defer = false;

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/aviagram.php' => $this->app->configPath('aviagram.php'),
        ], 'aviagram-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * The driver is registered by key rather than bound as a singleton: an
     * instance is only meaningful once it carries the credentials of one merchant
     * account, and there may be several. Build one via GatewayFactory.
     */
    public function register(): void
    {
        GatewayDriverRegistry::register(Services\AviagramGatewayService::class);

        $this->mergeConfigFrom(__DIR__ . '/config/aviagram.php', 'aviagram');
    }
}
