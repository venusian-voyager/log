<?php

namespace Voyager\Log\Context;

use Voyager\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Voyager\Queue\Events\JobProcessing;
use Voyager\Queue\Queue;
use Voyager\NutsAndBolts\DataObjects\Env;
use Voyager\NutsAndBolts\MagicAliases\Context;
use Voyager\NutsAndBolts\ServiceProvider;

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

        if ($this->app->runningInConsole()) {
            $this->app->resolving(Repository::class, function (Repository $repository) {
                $context = Env::get('__VENUSIAN_CONTEXT');

                if ($context && $context = json_decode($context, associative: true)) {
                    $repository->hydrate($context);
                }
            });
        }

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
        if (! class_exists(Queue::class)) {
            return;
        }

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            /** @phpstan-ignore staticMethod.notFound */
            $context = Context::dehydrate();

            return $context === null ? $payload : [
                ...$payload,
                'voyager:log:context' => $context,
            ];
        });

        $this->app['events']->listen(function (JobProcessing $event) {
            /** @phpstan-ignore staticMethod.notFound */
            Context::hydrate($event->job->payload()['voyager:log:context'] ?? null);
        });
    }
}
