<?php

namespace Voyager\Log;

use Voyager\NutsAndBolts\ServiceProvider;

class LogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton('log', fn ($app) => new LogManager($app));
    }
}
