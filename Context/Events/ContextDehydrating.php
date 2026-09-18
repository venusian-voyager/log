<?php

namespace Voyager\Log\Context\Events;

use Voyager\Log\Context\Repository;

class ContextDehydrating
{
    /**
     * The context instance.
     *
     * @var \Voyager\Log\Context\Repository
     */
    public Repository $context;

    /**
     * Create a new event instance.
     *
     * @param  \Voyager\Log\Context\Repository  $context
     */
    public function __construct(Repository $context)
    {
        $this->context = $context;
    }
}
