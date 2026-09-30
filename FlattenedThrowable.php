<?php

namespace Voyager\Log;

use Error;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Throwable;

/**
 * A caught exception as plain data, so a log call's context can cross into a pool worker. The trace
 * keeps every frame but drops the frames' arguments: those are what serialize() can't carry. The
 * worker rebuilds an exception of the same class, and the channel's formatter writes it as it would have.
 */
final readonly class FlattenedThrowable
{
    /**
     * @param class-string<Throwable> $class
     * @param list<array<string, mixed>> $trace frames without their args
     */
    public function __construct(
        public string $class,
        public string $message,
        public int|string $code,
        public string $file,
        public int $line,
        public array $trace,
        public ?FlattenedThrowable $previous,
    ) {}

    public static function from(Throwable $e): self
    {
        return new self(
            $e::class,
            $e->getMessage(),
            $e->getCode(),
            $e->getFile(),
            $e->getLine(),
            array_map(fn (array $frame): array => array_diff_key($frame, ['args' => true]), $e->getTrace()),
            is_null($e->getPrevious()) ? null : self::from($e->getPrevious()),
        );
    }

    /**
     * Every exception in a log context, at any depth, as data.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public static function flatten(array $context): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof Throwable => self::from($value),
            is_array($value) => self::flatten($value),
            default => $value,
        }, $context);
    }

    /**
     * The reverse of flatten(), run where the record is written.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public static function restore(array $context): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof self => $value->rebuild(),
            is_array($value) => self::restore($value),
            default => $value,
        }, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function rebuild(): Throwable
    {
        try {
            $e = new ReflectionClass($this->class)->newInstanceWithoutConstructor();
        } catch (ReflectionException) {
            // PHP's final internal exceptions refuse to skip their constructor; theirs takes no required arguments.
            $e = new ($this->class)();
        }

        $base = $e instanceof Error ? Error::class : Exception::class;

        foreach ([
            'message' => $this->message,
            'code' => $this->code,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => $this->trace,
            'previous' => $this->previous?->rebuild(),
        ] as $property => $value) {
            new ReflectionProperty($base, $property)->setValue($e, $value);
        }

        return $e;
    }
}
