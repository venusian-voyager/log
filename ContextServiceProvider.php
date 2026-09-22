<?php

namespace Voyager\Log;

use Voyager\Queue\Queue;
use Voyager\Log\Context\Repository;
use Voyager\Queue\Signals\JobProcessing;
use Voyager\NutsAndBolts\DataObjects\Env;
use Voyager\NutsAndBolts\ServiceProvider;
use Voyager\Log\Context\ContextLogProcessor;
use Voyager\NutsAndBolts\MagicAliases\Context;
use Voyager\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;

class ContextServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->scoped(Repository::class);

        $this->app->resolving(Repository::class, function (Repository $repository) {
            $context = Env::get('__VENUSIAN_CONTEXT');

            if ($context && $context = json_decode($context, associative: true)) {
                $repository->hydrate($context);
            }
        });

        $this->app->bind(ContextLogProcessorContract::class, fn () => new ContextLogProcessor());
    }

    /**
     * Boot the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Queue arrives in a later wave; context propagation across jobs wires
        // itself up once it does.
        /* @todo - bring these back up eventually
        if (! class_exists(Queue::class)) {
            return;
        }

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {

            $context = Context::dehydrate();

            return $context === null ? $payload : [
                ...$payload,
                'voyager:log:context' => $context,
            ];
        });

        $this->app['events']->listen(function (JobProcessing $event) {

            Context::hydrate($event->job->payload()['voyager:log:context'] ?? null);
        });*/
    }
}
