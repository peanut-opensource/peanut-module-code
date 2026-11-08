<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Domain\Logs;

use InvalidArgumentException;
use PeanutAdmin\Modules\Ops\Domain\Support\Contract;

final readonly class RuntimeLogQuery
{
    public function __construct(
        public string $sourceKey,
        public string $minimumSeverity,
        public ?string $cursor,
        public int $pageSize,
    ) {
        Contract::qualifiedKey($sourceKey, 64);
        if (!in_array($minimumSeverity, LogSeverity::VALUES, true)
            || $pageSize < 1 || $pageSize > 100
        ) {
            throw new InvalidArgumentException('Invalid log query.');
        }
        if ($cursor !== null) {
            Contract::opaqueKey($cursor, 'cursor_');
        }
    }
}
