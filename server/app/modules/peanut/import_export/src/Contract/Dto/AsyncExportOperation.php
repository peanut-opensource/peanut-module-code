<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract\Dto;

final readonly class AsyncExportOperation
{
    /** @param array<string,mixed> $payload */
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

    /** @param array<string,mixed> $payload */
    public static function fromPublicArray(array $payload): self
    {
        return new self(
            operationKey: (string) ($payload['operation_key'] ?? ''),
            providerKey: (string) ($payload['provider_key'] ?? ''),
            direction: (string) ($payload['direction'] ?? ''),
            format: (string) ($payload['format'] ?? 'csv'),
            status: (string) ($payload['status'] ?? ''),
            inputFileKey: self::nullableString($payload['input_file_key'] ?? null),
            resultFileKey: self::nullableString($payload['result_file_key'] ?? null),
            errorFileKey: self::nullableString($payload['error_file_key'] ?? null),
            taskJobKey: self::nullableString($payload['task_job_key'] ?? null),
            schemaRevision: (string) ($payload['schema_revision'] ?? ''),
            mapping: is_array($payload['mapping'] ?? null) ? $payload['mapping'] : [],
            processedRows: (int) ($payload['processed_rows'] ?? 0),
            acceptedRows: (int) ($payload['accepted_rows'] ?? 0),
            rejectedRows: (int) ($payload['rejected_rows'] ?? 0),
            totalRows: (int) ($payload['total_rows'] ?? 0),
            revision: (int) ($payload['revision'] ?? 0),
            lastErrorCode: self::nullableString($payload['last_error_code'] ?? null),
            retentionUntil: (string) ($payload['retention_until'] ?? ''),
            createdAt: (string) ($payload['created_at'] ?? ''),
            updatedAt: (string) ($payload['updated_at'] ?? ''),
            completedAt: self::nullableString($payload['completed_at'] ?? null),
        );
    }

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

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
