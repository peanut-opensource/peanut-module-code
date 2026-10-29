<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract;

use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface ImportExportQueries
{
    /** 已授权低层入口；非 HTTP 调用同样必须先取得可信 AuthorizedOperationContext。 */
    /** @return array{items:list<AsyncExportOperation>,page:int,page_size:int,total:int} */
    public function operations(
        AuthorizedOperationContext $context,
        string $status,
        int $page,
        int $pageSize,
    ): array;

    /** Returns Tenant-authorized asynchronous CSV operation status only. */
    public function operation(AuthorizedOperationContext $context, string $operationKey): AsyncExportOperation;

    /** Returns the active Tenant-owned operation for a private result file. */
    public function resultFile(AuthorizedOperationContext $context, string $fileKey): AsyncExportOperation;
}
