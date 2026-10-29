<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Engine\Contract;

use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;

final readonly class ExportBatch
{
    /** @param list<array<string, bool|int|float|string|null>> $rows */
    public function __construct(public array $rows, public ?string $nextCursor)
    {
        if (count($rows) > 500
            || ($nextCursor !== null && ($nextCursor === '' || strlen($nextCursor) > 512 || preg_match('/^[\x21-\x7e]+$/D', $nextCursor) !== 1))
        ) {
            throw ImportExportException::invalid();
        }
    }
}
