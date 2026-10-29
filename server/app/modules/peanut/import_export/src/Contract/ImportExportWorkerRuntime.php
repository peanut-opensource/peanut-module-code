<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract;

interface ImportExportWorkerRuntime
{
    public function runTenant(int $tenantId, string $workerId): int;
}
