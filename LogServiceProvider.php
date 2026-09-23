<?php

namespace Voyager\Log;

use Voyager\NutsAndBolts\ServiceProvider;

class LogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton('log', fn ($app) => new LogManager($app));
    }
}
