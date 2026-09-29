<?php

namespace Voyager\Log;

use Voyager\Queue\Queue;
use Voyager\Log\Context\Repository;
use Voyager\Vessel\ControlPanel;
use Voyager\Queue\Signals\JobProcessing;
use Voyager\NutsAndBolts\DataObjects\Env;
use Voyager\NutsAndBolts\ServiceProvider;
use Voyager\Log\Context\ContextLogProcessor;
use Voyager\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;

class ContextServiceProvider extends ServiceProvider
{
    private static bool $payload_hooked = false;

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
        // A pushed job carries the context it was dispatched in, and the worker takes it back up.
        // Payload callbacks are static on Queue, so the hook goes on once per process and reads
        // whichever app is current when a job is pushed.
        if (! self::$payload_hooked) {
            self::$payload_hooked = true;

            Queue::createPayloadUsing(function ($connection, $queue, $payload) {
                $context = ControlPanel::getInstance()->make(Repository::class)->dehydrate();

                return $context === null ? $payload : [
                    ...$payload,
                    'voyager:log:context' => $context,
                ];
            });
        }

        $this->app['signals']->listen(function (JobProcessing $event) {
            $this->app->make(Repository::class)->hydrate($event->job->payload()['voyager:log:context'] ?? null);
        });
    }
}
