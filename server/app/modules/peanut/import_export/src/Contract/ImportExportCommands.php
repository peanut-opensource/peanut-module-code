<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract;

use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\CsvExportOperation;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface ImportExportCommands
{
    /** Stable authorization resource; using the identifier does not grant permission. */
    public const RESOURCE_KEY = 'peanut.import-export';

    /**
     * 已授权低层入口：调用者必须先由管理用例或 worker 复核授权，不能自行伪造上下文。
     * Submits a CSV operation; it never writes or exposes a result file inline.
     */
    public function submitCsvExport(
        AuthorizedOperationContext $context,
        CsvExportOperation $operation,
    ): AsyncExportOperation;

    /** @param array<string,string> $mapping */
    public function submitImport(
        AuthorizedOperationContext $context,
        string $providerKey,
        string $fileKey,
        array $mapping,
        string $idempotencyKey,
    ): AsyncExportOperation;

    public function submitExport(
        AuthorizedOperationContext $context,
        string $providerKey,
        string $idempotencyKey,
    ): AsyncExportOperation;

    public function cancel(
        AuthorizedOperationContext $context,
        string $operationKey,
        int $revision,
    ): AsyncExportOperation;
}
