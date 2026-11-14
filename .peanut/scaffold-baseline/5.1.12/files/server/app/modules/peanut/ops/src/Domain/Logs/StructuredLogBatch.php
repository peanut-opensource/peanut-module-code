<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Domain\Logs;

use InvalidArgumentException;
use PeanutAdmin\Modules\Ops\Domain\Support\Contract;

final readonly class StructuredLogBatch
{
    /** @param list<StructuredLogRecord> $records */
    public function __construct(public array $records, public ?string $nextCursor)
    {
        if (count($records) > 100) {
            throw new InvalidArgumentException('Too many log records.');
        }
        if ($nextCursor !== null) {
            Contract::opaqueKey($nextCursor, 'cursor_');
        }
    }
}
