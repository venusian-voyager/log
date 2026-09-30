<?php

namespace Voyager\Log;

use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Logger as Monolog;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;
use Voyager\Log\Context\Repository as ContextRepository;
use Voyager\Vessel\ControlPanel;

/**
 * A channel's log calls, run in a pool worker exactly as the blocking calls would run them. The
 * worker has booted the app, so the channel, its taps and its processors resolve there. What only
 * the caller had travels with the calls: the channel's config, the Context each call was made in,
 * and any exception in the context, as a FlattenedThrowable.
 */
final readonly class WriteLog implements ShouldPool
{
    /**
     * @param array<string, array<string, mixed>> $configs the logging.channels entries the channel is built from: its own, and a stack's members'
     * @param list<array{level: string, message: string, context: array<array-key, mixed>, datetime: JsonSerializableDateTimeImmutable, snapshot: ?array}> $calls
     */
    public function __construct(
        public string $channel,
        public array $configs,
        public array $calls,
    ) {}

    /**
     * @return int how many calls were written
     */
    public function handle(): int
    {
        $app = ControlPanel::getInstance();

        /** @var LogManager $log */
        $log = $app->get('log');
        $logger = $log->replay($this->channel, $this->configs);

        $context = $app->isBound(ContextRepository::class) ? $app->get(ContextRepository::class) : null;

        // A stopped loop's leftovers run this in the caller: its own Context comes back afterwards.
        $own = $context?->dehydrate();

        try {
            foreach ($this->calls as $call) {
                $context?->hydrate($call['snapshot']);
                $record_context = FlattenedThrowable::restore($call['context']);

                $logger instanceof Monolog
                    ? $logger->addRecord(Monolog::toMonologLevel($call['level']), $call['message'], $record_context, $call['datetime'])
                    : $logger->log($call['level'], $call['message'], $record_context);
            }
        } finally {
            $context?->hydrate($own);
        }

        return count($this->calls);
    }
}
