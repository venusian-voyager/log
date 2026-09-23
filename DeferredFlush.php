<?php

namespace Voyager\Log;

use Monolog\Handler\BufferHandler;
use Voyager\Contracts\IOPools\Loop;

/**
 * Flushes a channel's buffers once per turn. Armed by the first record after a flush,
 * so a quiet channel costs the loop nothing.
 */
final class DeferredFlush
{
    private bool $armed = false;

    /** @param BufferHandler[] $buffers */
    public function __construct(private readonly Loop $loop, private readonly array $buffers)
    {
        $this->loop->onStop($this->flush(...));      // nothing is lost at shutdown
    }

    public function arm(): void
    {
        if ($this->armed) {
            return;
        }

        $this->armed = true;
        $this->loop->defer($this->flush(...));
    }

    public function flush(): void
    {
        $this->armed = false;

        foreach ($this->buffers as $buffer) {
            $buffer->flush();
        }
    }
}
