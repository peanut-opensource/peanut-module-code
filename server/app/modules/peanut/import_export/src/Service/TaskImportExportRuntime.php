<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportQueries;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportWorkerRuntime;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;

final readonly class TaskImportExportRuntime implements ImportExportWorkerRuntime
{
    public function __construct(
        private ImportExportCommands $commands,
        private ImportExportQueries $queries,
        private TaskJobRuntime $tasks,
        private AppFileMediaGateway $files,
    ) {}

    public function commands(): ImportExportCommands
    {
        return $this->commands;
    }

    public function queries(): ImportExportQueries
    {
        return $this->queries;
    }

    public function operation(AuthorizedOperationContext $context, string $operationKey): AsyncExportOperation
    {
        return $this->queries()->operation($this->asOperation($context, 'read'), $operationKey);
    }

    /** @return array{url:string,filename:string} */
    public function download(AuthorizedOperationContext $context, string $fileKey): array
    {
        $read = $this->asOperation($context, 'read');
        $this->queries()->resultFile($read, $fileKey);
        return $this->files->download($read, $fileKey);
    }

    public function runTenant(int $tenantId, string $workerId): int
    {
        return $this->tasks->runTenant($tenantId, $workerId);
    }

    private function asOperation(AuthorizedOperationContext $source, string $operation): AuthorizedOperationContext
    {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $source->tenantContext,
            ImportExportService::RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', $source->authorizationBasisDigest . '|async-export|' . $operation),
        ));
    }

}
