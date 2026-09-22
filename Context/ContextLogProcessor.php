<?php

namespace Voyager\Log\Context;

use Monolog\LogRecord;
use Voyager\Vessel\ControlPanel;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Voyager\Log\Context\Repository as ContextRepository;
use Voyager\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;

class ContextLogProcessor implements ContextLogProcessorContract
{
    /**
     * Add contextual data to the log's "extra" parameter.
     *
     * @param LogRecord $record
     * @return LogRecord
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $app = ControlPanel::getInstance();

        if (! $app->isBound(ContextRepository::class)) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            ...$app->get(ContextRepository::class)->all(),
        ]);
    }
}
