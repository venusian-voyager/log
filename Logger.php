<?php

namespace Voyager\Log;

use Closure;
use Stringable;
use RuntimeException;
use Psr\Log\LoggerInterface;
use Voyager\Log\Signals\MessageLogged;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\NutsAndBolts\Jsonable;
use Voyager\Contracts\NutsAndBolts\Arrayable;
use Voyager\Contracts\Signals\SignalDispatcher;
use Voyager\NutsAndBolts\Concerns\Conditionable;

class Logger implements LoggerInterface
{
    use Conditionable;

    /**
     * The underlying logger implementation.
     *
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * The event dispatcher instance.
     *
     * @var SignalDispatcher|null
     */
    protected ?SignalDispatcher $dispatcher;

    /**
     * Any context to be added to logs.
     *
     * @var array
     */
    protected array $context = [];

    /**
     * Create a new log writer instance.
     *
     * @param LoggerInterface $logger
     * @param SignalDispatcher|null $dispatcher
     * @param AsyncWrites|null $writes carries the *Async() calls to a worker pool
     * @param string $channel the name a worker resolves this channel by
     * @param array<string, array<string, mixed>> $configs the logging.channels entries a worker builds it from
     */
    public function __construct(
        LoggerInterface $logger,
        ?SignalDispatcher $dispatcher = null,
        protected readonly ?AsyncWrites $writes = null,
        protected readonly string $channel = '',
        protected readonly array $configs = [],
    ) {
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
    }

    public function emergency(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('emergency', $message, $context);
    }

    public function alert(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('alert', $message, $context);
    }

    public function critical(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('critical', $message, $context);
    }

    public function error(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('error', $message, $context);
    }

    public function warning(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('warning', $message, $context);
    }

    public function notice(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('notice', $message, $context);
    }

    public function info(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('info', $message, $context);
    }

    public function debug(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog('debug', $message, $context);
    }

    /**
     * Log a message to the logs.
     *
     * @param mixed $level a level name: PSR-3 leaves the parameter untyped
     */
    public function log($level, Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context);
    }

    /**
     * Dynamically pass log calls into the writer.
     */
    public function write(string $level, Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context);
    }

    public function emergencyAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('emergency', $message, $context);
    }

    public function alertAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('alert', $message, $context);
    }

    public function criticalAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('critical', $message, $context);
    }

    public function errorAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('error', $message, $context);
    }

    public function warningAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('warning', $message, $context);
    }

    public function noticeAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('notice', $message, $context);
    }

    public function infoAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('info', $message, $context);
    }

    public function debugAsync(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync('debug', $message, $context);
    }

    public function logAsync(string $level, Arrayable|Jsonable|Stringable|array|string $message, array $context = []): Promise
    {
        return $this->writeLogAsync($level, $message, $context);
    }

    /**
     * Write a message to the log.
     */
    protected function writeLog(string $level, Arrayable|Jsonable|Stringable|array|string $message, array $context): void
    {
        if (method_exists($this->logger, 'isHandling') && ! $this->logger->isHandling($level)) {
            return;
        }

        $this->logger->{$level}(
            $message = $this->formatMessage($message),
            $context = array_merge($this->context, $context)
        );

        $this->fireLogEvent($level, $message, $context);
    }

    /**
     * Hand a message to a pool worker, which writes it through this channel exactly as writeLog() would.
     * Everything up to the write happens here: the level check, the message format, the context merge,
     * and MessageLogged, so this process's listeners hear async lines too.
     */
    protected function writeLogAsync(string $level, Arrayable|Jsonable|Stringable|array|string $message, array $context): Promise
    {
        if (is_null($this->writes)) {
            throw new RuntimeException('This logger has no pool to write through: async calls need a logger built by the LogManager.');
        }

        if (method_exists($this->logger, 'isHandling') && ! $this->logger->isHandling($level)) {
            return $this->writes->nothing();
        }

        $message = $this->formatMessage($message);
        $context = array_merge($this->context, $context);

        $promise = $this->writes->submit($this->channel, $this->configs, $level, $message, $context);

        $this->fireLogEvent($level, $message, $context);

        return $promise;
    }

    /**
     * Add context to all future logs.
     *
     * @param  array  $context
     * @return $this
     */
    public function withContext(array $context = []): static
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * Flush the log context on all currently resolved channels.
     *
     * @param  string[]|null  $keys
     * @return $this
     */
    public function withoutContext(?array $keys = null): static
    {
        if (is_array($keys)) {
            $this->context = array_diff_key($this->context, array_flip($keys));
        } else {
            $this->context = [];
        }

        return $this;
    }

    /**
     * Register a new callback handler for when a log event is triggered.
     *
     * @throws RuntimeException
     */
    public function listen(Closure $callback): void
    {
        if (! isset($this->dispatcher)) {
            throw new RuntimeException('Events dispatcher has not been set.');
        }

        $this->dispatcher->listen(MessageLogged::class, $callback);
    }

    /**
     * Fires a log event.
     */
    protected function fireLogEvent(string $level, string $message, array $context = []): void
    {
        // Avoid dispatching the event multiple times if our logger instance is the LogManager...
        if ($this->logger instanceof LogManager &&
            $this->logger->getEventDispatcher() !== null) {
            return;
        }

        // If the event dispatcher is set, we will pass along the parameters to the
        // log listeners. These are useful for building profilers or other tools
        // that aggregate every log message for a given "request" cycle.
        $this->dispatcher?->dispatch(new MessageLogged($level, $message, $context));
    }

    /**
     * Format the parameters for the logger.
     */
    protected function formatMessage(Arrayable|Jsonable|Stringable|array|string $message): string
    {
        return match (true) {
            is_array($message) => var_export($message, true),
            $message instanceof Jsonable => $message->toJson(),
            $message instanceof Arrayable => var_export($message->toArray(), true),
            default => (string) $message,
        };
    }

    /**
     * Get the underlying logger implementation.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get the event dispatcher instance.
     *
     * @return SignalDispatcher|null
     */
    public function getEventDispatcher(): ?SignalDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     *
     * @param SignalDispatcher $dispatcher
     * @return void
     */
    public function setEventDispatcher(SignalDispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Dynamically proxy method calls to the underlying logger.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->logger->{$method}(...$parameters);
    }
}
