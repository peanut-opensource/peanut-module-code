<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Service;

use PeanutAdmin\Modules\ImportExport\Contract\Dto\AsyncExportOperation;
use PeanutAdmin\Modules\ImportExport\Contract\Dto\CsvExportOperation;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportQueries;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Modules\ImportExport\Engine\Application\OperationRecord;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class ImportExportApplicationService implements ImportExportCommands, ImportExportQueries
{
    public function __construct(private ImportExportService $service) {}

    public function submitCsvExport(
        AuthorizedOperationContext $context,
        CsvExportOperation $operation,
    ): AsyncExportOperation {
        return $this->toOperation($this->service->submitExport(
            $context,
            $operation->providerKey,
            $operation->idempotencyKey,
        ));
    }

    /** @param array<string,string> $mapping */
    public function submitImport(
        AuthorizedOperationContext $context,
        string $providerKey,
        string $fileKey,
        array $mapping,
        string $idempotencyKey,
    ): AsyncExportOperation {
        return $this->toOperation($this->service->submitImport(
            $context,
            $providerKey,
            $fileKey,
            $mapping,
            $idempotencyKey,
        ));
    }

    public function submitExport(
        AuthorizedOperationContext $context,
        string $providerKey,
        string $idempotencyKey,
    ): AsyncExportOperation {
        return $this->toOperation($this->service->submitExport($context, $providerKey, $idempotencyKey));
    }

    /** @return array{items:list<AsyncExportOperation>,page:int,page_size:int,total:int} */
    public function operations(AuthorizedOperationContext $context, string $status, int $page, int $pageSize): array
    {
        $result = $this->service->list($context, $status, $page, $pageSize);
        return [
            'items' => array_map(fn(OperationRecord $item): AsyncExportOperation => $this->toOperation($item), $result['items']),
            'page' => $result['page'],
            'page_size' => $result['page_size'],
            'total' => $result['total'],
        ];
    }

    public function cancel(
        AuthorizedOperationContext $context,
        string $operationKey,
        int $revision,
    ): AsyncExportOperation {
        return $this->toOperation($this->service->cancel($context, $operationKey, $revision));
    }

    public function operation(AuthorizedOperationContext $context, string $operationKey): AsyncExportOperation
    {
        return $this->toOperation($this->service->detail($context, $operationKey));
    }

    public function resultFile(AuthorizedOperationContext $context, string $fileKey): AsyncExportOperation
    {
        return $this->toOperation($this->service->resultFile($context, $fileKey));
    }

    private function toOperation(OperationRecord $operation): AsyncExportOperation
    {
        return new AsyncExportOperation(
            operationKey: $operation->operationKey,
            providerKey: $operation->providerKey,
            direction: $operation->direction,
            format: 'csv',
            status: $operation->status,
            inputFileKey: $operation->inputFileKey,
            resultFileKey: $operation->resultFileKey,
            errorFileKey: $operation->errorFileKey,
            taskJobKey: $operation->taskJobKey,
            schemaRevision: $operation->schemaRevision,
            mapping: $operation->mapping,
            processedRows: $operation->processedRows,
            acceptedRows: $operation->acceptedRows,
            rejectedRows: $operation->rejectedRows,
            totalRows: $operation->totalRows,
            revision: $operation->revision,
            lastErrorCode: $operation->lastErrorCode,
            retentionUntil: $operation->retentionUntil,
            createdAt: $operation->createdAt,
            updatedAt: $operation->updatedAt,
            completedAt: $operation->completedAt,
        );
    }
}
