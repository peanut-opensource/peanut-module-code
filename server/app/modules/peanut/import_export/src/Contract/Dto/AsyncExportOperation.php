<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract\Dto;

final readonly class AsyncExportOperation
{
    public function __construct(
        public string $operationKey,
        public string $providerKey,
        public string $direction,
        public string $format,
        public string $status,
        public ?string $inputFileKey,
        public ?string $resultFileKey,
        public ?string $errorFileKey,
        public ?string $taskJobKey,
        public string $schemaRevision,
        public array $mapping,
        public int $processedRows,
        public int $acceptedRows,
        public int $rejectedRows,
        public int $totalRows,
        public int $revision,
        public ?string $lastErrorCode,
        public string $retentionUntil,
        public string $createdAt,
        public string $updatedAt,
        public ?string $completedAt,
    ) {}

    /** @return array<string,mixed> */
    public function toPublicArray(): array
    {
        return [
            'operation_key' => $this->operationKey,
            'provider_key' => $this->providerKey,
            'direction' => $this->direction,
            'format' => $this->format,
            'status' => $this->status,
            'input_file_key' => $this->inputFileKey,
            'result_file_key' => $this->resultFileKey,
            'error_file_key' => $this->errorFileKey,
            'task_job_key' => $this->taskJobKey,
            'schema_revision' => $this->schemaRevision,
            'mapping' => $this->mapping,
            'processed_rows' => $this->processedRows,
            'accepted_rows' => $this->acceptedRows,
            'rejected_rows' => $this->rejectedRows,
            'total_rows' => $this->totalRows,
            'revision' => $this->revision,
            'last_error_code' => $this->lastErrorCode,
            'retention_until' => $this->retentionUntil,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
        ];
    }

}
