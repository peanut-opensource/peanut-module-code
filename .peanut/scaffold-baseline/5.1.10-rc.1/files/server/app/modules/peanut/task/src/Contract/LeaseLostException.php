<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use RuntimeException;
use Throwable;

/** Signals that a worker must stop without committing any more local effects. */
final class LeaseLostException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('TASK_LEASE_LOST', 0, $previous);
    }
}
