<?php

namespace Voyager\Log;

use InvalidArgumentException;
use Monolog\JsonSerializableDateTimeImmutable;
use Throwable;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\WorkerPools\WorkerPool;
use Voyager\Log\Context\Repository as ContextRepository;

/**
 * Hands async log calls to a worker pool as WriteLog gigs: the thread pool when it is on, the
 * process pool otherwise. Each channel has one gig out at a time. Calls made while it is out wait
 * and go together in the next one, so lines land in the order they were logged however many
 * workers the pool has.
 */
final class AsyncWrites
{
    /**
     * @var array<string, list<array{configs: array<string, array<string, mixed>>, call: array<string, mixed>, promise: Promise}>> channel => calls waiting for the channel's gig
     */
    private array $queued = [];

    /**
     * @var array<string, true> channels with a gig out
     */
    private array $out = [];

    private ?Loop $loop = null;

    private ?WorkerPool $pool = null;

    public function __construct(private readonly FrameworkCore $app) {}

    /**
     * Queue one call on its channel. The promise settles once a worker has written it.
     *
     * @param array<string, array<string, mixed>> $configs
     * @param array<array-key, mixed> $context
     */
    public function submit(string $channel, array $configs, string $level, string $message, array $context): Promise
    {
        $this->pool();
        $promise = $this->loop()->promise();

        try {
            $call = [
                'level' => $level,
                'message' => $message,
                'context' => FlattenedThrowable::flatten($context),
                'datetime' => new JsonSerializableDateTimeImmutable(true),
                'snapshot' => $this->app->isBound(ContextRepository::class)
                    ? $this->app->get(ContextRepository::class)->dehydrate()
                    : null,
            ];

            // Proven here, one call at a time: a call that can't cross would sink every call batched with it.
            serialize([$configs, $call]);
        } catch (Throwable $e) {
            $promise->reject(new InvalidArgumentException("This log call can't cross to a worker: {$e->getMessage()}", 0, $e));

            return $promise;
        }

        $this->queued[$channel][] = ['configs' => $configs, 'call' => $call, 'promise' => $promise];

        if (! isset($this->out[$channel])) {
            $this->send($channel);
        }

        return $promise;
    }

    /**
     * A call the channel doesn't handle at its level: nothing to write, already settled.
     */
    public function nothing(): Promise
    {
        $promise = $this->loop()->promise();
        $promise->resolve(null);

        return $promise;
    }

    /**
     * The loop stopped, so nothing will carry the queued calls to a worker: write them here,
     * blocking, in order. A gig already out belongs to the pool, whose shutDown() settles it.
     */
    public function flush(): void
    {
        [$queued, $this->queued] = [$this->queued, []];

        foreach ($queued as $channel => $waiting) {
            foreach ($waiting as $entry) {
                try {
                    new WriteLog($channel, $entry['configs'], [$entry['call']])->handle();
                    $entry['promise']->resolve(null);
                } catch (Throwable $e) {
                    $entry['promise']->reject($e);
                }
            }
        }
    }

    /**
     * Process exit: let the gigs that are out come back (a script that never ran the loop borrows
     * it here), then write whatever is still queued.
     */
    public function drain(): void
    {
        if (! is_null($this->loop)) {
            try {
                $this->loop->until(fn (): bool => $this->out === []);
            } catch (Throwable) {
                // The loop was stopped or ran out of work first: flush() writes what is left.
            }
        }

        $this->flush();
    }

    private function send(string $channel): void
    {
        $waiting = $this->queued[$channel] ?? [];

        if ($waiting === []) {
            unset($this->queued[$channel], $this->out[$channel]);
            return;
        }

        // One gig carries one channel config: a channel rebuilt with new config starts the next gig.
        $configs = $waiting[0]['configs'];
        $batch = [];

        while ($waiting !== [] && $waiting[0]['configs'] === $configs) {
            $batch[] = array_shift($waiting);
        }

        $this->queued[$channel] = $waiting;
        $this->out[$channel] = true;

        $settle = function (?Throwable $failure) use ($channel, $batch): void {
            foreach ($batch as $entry) {
                is_null($failure) ? $entry['promise']->resolve(null) : $entry['promise']->reject($failure);
            }

            $this->send($channel);
        };

        try {
            $gig = $this->pool()->submit(new WriteLog($channel, $configs, array_column($batch, 'call')));
        } catch (Throwable $e) {
            $settle($e);
            return;
        }

        $gig->then(fn (): mixed => $settle(null));
        $gig->error(fn (Throwable $e): mixed => $settle($e));
    }

    private function loop(): Loop
    {
        if (is_null($this->loop)) {
            $this->loop = $this->app->get(Loop::class);
            $this->loop->onStop($this->flush(...));
            register_shutdown_function($this->drain(...));
        }

        return $this->loop;
    }

    private function pool(): WorkerPool
    {
        return $this->pool ??= match (true) {
            $this->app->isBound('thread-workers') => $this->app->get('thread-workers'),
            $this->app->isBound('process-workers') => $this->app->get('process-workers'),
            default => throw new InvalidArgumentException(
                'Async logging writes through a worker pool, and none is on: enable io-pools.pool_workers.threads or io-pools.pool_workers.process.'
            ),
        };
    }
}
