<?php

namespace Voyager\Log;

use Closure;
use InvalidArgumentException;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Processor\ProcessorInterface;
use Monolog\Processor\PsrLogMessageProcessor;
use Throwable;
use Stringable;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Psr\Log\LoggerInterface;
use Monolog\Logger as Monolog;
use Monolog\Handler\StreamHandler;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\Log\ContextLogProcessor;
use Voyager\NutsAndBolts\Collection;
use Voyager\NutsAndBolts\DataObjects\Str;
use Voyager\Contracts\Core\FrameworkCore;

class LogManager implements LoggerInterface
{
    use ParsesLogConfiguration;

    /**
     * The application instance.
     *
     * @var FrameworkCore
     */
    protected FrameworkCore $app;

    /**
     * The array of resolved channels.
     *
     * @var array
     */
    protected array $channels = [];

    /**
     * The context shared across channels and stacks.
     *
     * @var array
     */
    protected array $sharedContext = [];

    /**
     * The registered custom driver creators.
     *
     * @var array
     */
    protected array $custom_creators = [];

    /**
     * The standard date format to use when writing logs.
     *
     * @var string
     */
    protected string $date_format = 'Y-m-d H:i:s';

    /**
     * Create a new Log manager instance.
     *
     * @param FrameworkCore $app
     */
    public function __construct(FrameworkCore $app)
    {
        $this->app = $app;
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->driver()->emergency($message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->driver()->alert($message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->driver()->critical($message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->driver()->error($message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->driver()->warning($message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->driver()->notice($message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->driver()->info($message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->driver()->debug($message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->driver()->log($level, $message, $context);
    }

    /**
     * Build an on-demand log channel.
     *
     * @param  array  $config
     * @return LoggerInterface
     */
    public function build(array $config): LoggerInterface
    {
        unset($this->channels['ondemand']);

        return $this->get('ondemand', $config);
    }

    /**
     * Create a new, on-demand aggregate logger instance.
     *
     * @param  array  $channels
     * @param  string|null  $channel
     * @return LoggerInterface
     */
    public function stack(array $channels, $channel = null): LoggerInterface
    {
        return new Logger(
            $this->createStackDriver(compact('channels', 'channel')),
            $this->app['signals']
        )->withContext($this->sharedContext);
    }

    /**
     * Get a log channel instance.
     *
     * @param string|null $channel
     * @return LoggerInterface
     */
    public function channel(?string $channel = null): LoggerInterface
    {
        return $this->driver($channel);
    }

    /**
     * Get a log driver instance.
     *
     * @param string|null $driver
     * @return LoggerInterface
     */
    public function driver(?string $driver = null): LoggerInterface
    {
        return $this->get($this->parseDriver($driver));
    }

    /**
     * Get the default log driver name.
     *
     * @return string|null
     */
    public function getDefaultDriver(): ?string
    {
        return $this->app['config']['logging.default'];
    }

    /**
     * Get fallback log channel name.
     *
     * @return string
     */
    protected function getFallbackChannelName(): string
    {
        return $this->app->isBound('env') ? $this->app->environment() : 'production';
    }

    /**
     * Create an aggregate log driver instance.
     *
     * @param  array  $config
     * @return LoggerInterface
     */
    protected function createStackDriver(array $config): LoggerInterface
    {
        if (is_string($config['channels'])) {
            $config['channels'] = explode(',', $config['channels']);
        }

        $handlers = new Collection($config['channels'])
            ->flatMap(function ($channel) {
                return $channel instanceof LoggerInterface
                    ? $channel->getHandlers()
                    : $this->channel($channel)->getHandlers();
            })
            ->all();

        $processors = new Collection($config['channels'])
            ->flatMap(function ($channel) {
                return $channel instanceof LoggerInterface
                    ? $channel->getProcessors()
                    : $this->channel($channel)->getProcessors();
            })
            ->all();

        if ($config['ignore_exceptions'] ?? false) {
            $handlers = [new WhatFailureGroupHandler($handlers)];
        }

        return new Monolog($this->parseChannel($config), $handlers, $processors);
    }

    /**
     * Buffer another channel and write it once per loop turn.
     */
    protected function createDeferredDriver(array $config): LoggerInterface
    {
        $inner = $this->channel($config['channel']);
        $monolog = $inner instanceof Logger ? $inner->getLogger() : $inner;

        $buffers = array_map(
            fn ($handler) => new BufferHandler($handler, $config['limit'] ?? 0, Level::Debug, true, false),
            $monolog->getHandlers(),
        );

        $flush = new DeferredFlush($this->app->make(Loop::class), $buffers);

        $arming = new class($flush) implements HandlerInterface {
            public function __construct(private readonly DeferredFlush $flush) {}
            public function isHandling(LogRecord $record): bool { return true; }
            public function handle(LogRecord $record): bool { $this->flush->arm(); return false; }   // false: let the buffers see it too
            public function handleBatch(array $records): void { $this->flush->arm(); }
            public function close(): void {}
        };

        return new Logger(
            new Monolog($this->parseChannel($config), [$arming, ...$buffers], $monolog->getProcessors()),
            $this->app['signals'],
        );
    }

    /**
     * Create a custom log driver instance.
     *
     * @param  array  $config
     * @return LoggerInterface
     */
    protected function createCustomDriver(array $config): LoggerInterface
    {
        $factory = is_callable($via = $config['via']) ? $via : $this->app->make($via);

        return $factory($config);
    }

    /**
     * Create an instance of the single file log driver.
     *
     * @param  array  $config
     * @return LoggerInterface
     */
    protected function createSingleDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(
                new StreamHandler(
                    $config['path'], $this->level($config),
                    $config['bubble'] ?? true, $config['permission'] ?? null, $config['locking'] ?? false
                ), $config
            ),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of the daily file log driver.
     *
     * @param  array  $config
     * @return LoggerInterface
     */
    protected function createDailyDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(new RotatingFileHandler(
                $config['path'], $config['days'] ?? 7, $this->level($config),
                $config['bubble'] ?? true, $config['permission'] ?? null, $config['locking'] ?? false
            ), $config),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of the syslog log driver.
     *
     * @param  array  $config
     * @return LoggerInterface
     */
    protected function createSyslogDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(new SyslogHandler(
                Str::snake($this->app['config']['app.name'], '-'),
                $config['facility'] ?? LOG_USER, $this->level($config)
            ), $config),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of the "error log" log driver.
     *
     * @param  array  $config
     * @return LoggerInterface
     */
    protected function createErrorlogDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(new ErrorLogHandler(
                $config['type'] ?? ErrorLogHandler::OPERATING_SYSTEM, $this->level($config)
            )),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of any handler available in Monolog.
     *
     * @param  array  $config
     * @return LoggerInterface
     *
     * @throws InvalidArgumentException
     */
    protected function createMonologDriver(array $config): LoggerInterface
    {
        if (! is_a($config['handler'], HandlerInterface::class, true)) {
            throw new InvalidArgumentException(
                $config['handler'].' must be an instance of '.HandlerInterface::class
            );
        }

        (new Collection($config['processors'] ?? []))->each(function ($processor) {
            $processor = $processor['processor'] ?? $processor;

            if (! is_a($processor, ProcessorInterface::class, true)) {
                throw new InvalidArgumentException(
                    $processor.' must be an instance of '.ProcessorInterface::class
                );
            }
        });

        $with = array_merge(
            ['level' => $this->level($config)],
            $config['with'] ?? [],
            $config['handler_with'] ?? []
        );

        $handler = $this->prepareHandler(
            $this->app->make($config['handler'], $with), $config
        );

        $processors = (new Collection($config['processors'] ?? []))
            ->map(fn ($processor) => $this->app->make($processor['processor'] ?? $processor, $processor['with'] ?? []))
            ->toArray();

        return new Monolog(
            $this->parseChannel($config),
            [$handler],
            $processors,
        );
    }

    /**
     * Attempt to get the log from the local cache.
     *
     * @param  string|null  $name
     * @param  array|null  $config
     * @return LoggerInterface
     */
    protected function get(?string $name, ?array $config = null): LoggerInterface
    {
        try {
            if (is_null($name)) {
                throw new InvalidArgumentException('Log [] is not defined.');
            }

            return $this->channels[$name] ?? with($this->resolve($name, $config), function ($logger) use ($name) {
                $loggerWithContext = $this->tap(
                    $name,
                    new Logger($logger, $this->app['signals'])
                )->withContext($this->sharedContext);

                if (method_exists($loggerWithContext->getLogger(), 'pushProcessor')) {
                    $loggerWithContext->pushProcessor($this->app->make(ContextLogProcessor::class));
                }

                return $this->channels[$name] = $loggerWithContext;
            });
        } catch (Throwable $e) {
            return tap($this->createEmergencyLogger(), function ($logger) use ($e) {
                $logger->emergency('Unable to create configured logger. Using emergency logger.', [
                    'exception' => $e,
                ]);
            });
        }


    }
    /**
     * Apply the configured taps for the logger.
     *
     * @param string $name
     * @param Logger $logger
     * @return Logger
     */
    protected function tap(string $name, Logger $logger): Logger
    {
        foreach ($this->configurationFor($name)['tap'] ?? [] as $tap) {
            [$class, $arguments] = $this->parseTap($tap);

            $this->app->make($class)->__invoke($logger, ...explode(',', $arguments));
        }

        return $logger;
    }

    /**
     * Parse the given tap class string into a class name and arguments string.
     *
     * @param string $tap
     * @return array
     */
    protected function parseTap(string $tap): array
    {
        return str_contains($tap, ':') ? explode(':', $tap, 2) : [$tap, ''];
    }

    /**
     * Get the log connection configuration.
     *
     * @param string $name
     * @return array|null
     */
    protected function configurationFor(string $name): ?array
    {
        return $this->app['config']["logging.channels.{$name}"];
    }

    /**
     * Create an emergency log handler to avoid white screens of death.
     *
     * @return LoggerInterface
     */
    protected function createEmergencyLogger(): LoggerInterface
    {
        $config = $this->configurationFor('emergency');

        $handler = new StreamHandler(
            $config['path'] ?? $this->app->storagePath().'/logs/venusian.log',
            $this->level(['level' => 'debug'])
        );

        return new Logger(
            new Monolog('venusian', $this->prepareHandlers([$handler])),
            $this->app['signals']
        );
    }

    /**
     * Prepare the handlers for usage by Monolog.
     *
     * @param  array  $handlers
     * @return array
     */
    protected function prepareHandlers(array $handlers): array
    {
        foreach ($handlers as $key => $handler) {
            $handlers[$key] = $this->prepareHandler($handler);
        }

        return $handlers;
    }

    /**
     * Prepare the handler for usage by Monolog.
     *
     * @param HandlerInterface $handler
     * @param  array  $config
     * @return HandlerInterface
     */
    protected function prepareHandler(HandlerInterface $handler, array $config = []): HandlerInterface
    {
        if (isset($config['action_level'])) {
            $handler = new FingersCrossedHandler(
                $handler,
                $this->actionLevel($config),
                0,
                true,
                $config['stop_buffering'] ?? true
            );
        }

        if (! $handler instanceof FormattableHandlerInterface) {
            return $handler;
        }

        if (! isset($config['formatter'])) {
            $handler->setFormatter($this->formatter());
        } elseif ($config['formatter'] !== 'default') {
            $handler->setFormatter($this->app->make($config['formatter'], $config['formatter_with'] ?? []));
        }

        return $handler;
    }

    /**
     * Get a Monolog formatter instance.
     *
     * @return FormatterInterface
     */
    protected function formatter(): FormatterInterface
    {
        return new LineFormatter(null, $this->date_format, true, true, true);
    }

    /**
     * Parse the driver name.
     *
     * @param  string|null  $driver
     * @return string|null
     */
    protected function parseDriver($driver): ?string
    {
        $driver ??= $this->getDefaultDriver();

        if ($this->app->runningUnitTests()) {
            $driver ??= 'null';
        }

        if ($driver === null) {
            return null;
        }

        return trim($driver);
    }

    /**
     * Resolve the given log instance by name.
     *
     * @param string $name
     * @param  array|null  $config
     * @return LoggerInterface
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name, ?array $config = null): LoggerInterface
    {
        $config ??= $this->configurationFor($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Log [{$name}] is not defined.");
        }

        if (isset($this->custom_creators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create'.ucfirst($config['driver']).'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     *
     * @param  array  $config
     * @return mixed
     */
    protected function callCustomCreator(array $config): mixed
    {
        return $this->custom_creators[$config['driver']]($this->app, $config);
    }

    /**
     * Share context across channels and stacks.
     *
     * @param  array  $context
     * @return $this
     */
    public function shareContext(array $context): static
    {
        foreach ($this->channels as $channel) {
            $channel->withContext($context);
        }

        $this->sharedContext = array_merge($this->sharedContext, $context);

        return $this;
    }

    /**
     * The context shared across channels and stacks.
     *
     * @return array
     */
    public function sharedContext(): array
    {
        return $this->sharedContext;
    }

    /**
     * Flush the log context on all currently resolved channels.
     *
     * @param  string[]|null  $keys
     * @return $this
     */
    public function withoutContext(?array $keys = null): static
    {
        foreach ($this->channels as $channel) {
            if (method_exists($channel, 'withoutContext')) {
                $channel->withoutContext($keys);
            }
        }

        return $this;
    }

    /**
     * Flush the shared context.
     *
     * @return $this
     */
    public function flushSharedContext(): static
    {
        $this->sharedContext = [];

        return $this;
    }

    /**
     * Set the default log driver name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultDriver($name): void
    {
        $this->app['config']['logging.default'] = $name;
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver
     * @param  Closure  $callback
     * @return $this
     */
    public function extend($driver, Closure $callback): static
    {
        $this->custom_creators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Unset the given channel instance.
     *
     * @param  string|null  $driver
     * @return void
     */
    public function forgetChannel($driver = null): void
    {
        $driver = $this->parseDriver($driver);

        if (isset($this->channels[$driver])) {
            unset($this->channels[$driver]);
        }
    }

    /**
     * Get every resolved log channel.
     *
     * @return array
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->$method(...$parameters);
    }
}