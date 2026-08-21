<?php

namespace Voyager\Log\Context;

use Voyager\Vessel\Vessel;
use Voyager\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Voyager\Log\Context\Repository as ContextRepository;
use Monolog\LogRecord;

class ContextLogProcessor implements ContextLogProcessorContract
{
    /**
     * Add contextual data to the log's "extra" parameter.
     *
     * @param  \Monolog\LogRecord  $record
     * @return \Monolog\LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $app = Vessel::getInstance();

        if (! $app->bound(ContextRepository::class)) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            ...$app->get(ContextRepository::class)->all(),
        ]);
    }
}
