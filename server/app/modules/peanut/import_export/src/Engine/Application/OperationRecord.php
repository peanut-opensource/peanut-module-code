<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Engine\Application;

final readonly class OperationRecord
{
    /** @param array<string, string> $mapping */
    public function __construct(
        public int $id,
        public string $operationKey,
        public int $tenantId,
        public int $createdByMemberId,
        public string $providerKey,
        public string $direction,
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
        public int $attemptNumber,
        public int $revision,
        public ?string $lastErrorCode,
        public string $retentionUntil,
        public string $createdAt,
        public string $updatedAt,
        public ?string $completedAt,
    ) {}

}
