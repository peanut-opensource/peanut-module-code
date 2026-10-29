<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Engine\Execution;

use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportException;
use PeanutAdmin\Modules\ImportExport\Engine\Application\OperationRecord;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProviderRegistry;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\RowIssue;
use PeanutAdmin\Modules\ImportExport\Engine\File\FileMediaGateway;
use PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;
use PeanutAdmin\Modules\Task\Contract\RetryableTaskException;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\LeaseLostException;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use Throwable;

final readonly class CsvOperationRunner
{
    private const MAX_ROWS = 100000;
    private const MAX_BYTES = 16777216;

    public function __construct(
        private ImportExportStore $repository,
        private DataProviderRegistry $providers,
        private FileMediaGateway $files,
        private AuditService $audit,
    ) {}

    public function run(AuthorizedOperationContext $context, string $operationKey, JobExecution $execution): OperationRecord
    {
        $jobKey = $execution->jobKey;
        $attempt = $execution->attemptNumber;
        $execution->checkpoint();
        $operation = $this->beginAttempt($execution, $context->tenantContext->tenantId, $operationKey, $jobKey, $attempt);
        try {
            $this->audit($execution, $context, $operation, 'started', ['attempt' => $attempt]);
            $provider = $this->providers->require($operation->providerKey);
            if (!hash_equals($operation->schemaRevision, $provider->schema()->revision)) {
                $execution->assertLeaseOwned();
                $result = $this->finish($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, 0, 'IMPORT_EXPORT_SCHEMA_MISMATCH');
                $this->audit($execution, $context, $result, 'failed', ['error_code' => 'IMPORT_EXPORT_SCHEMA_MISMATCH']);
                return $result;
            }
            $result = $operation->direction === 'import'
                ? $this->runImport($context, $operation, $execution)
                : $this->runExport($context, $operation, $execution);
            $this->audit($execution, $context, $result, $result->status, ['processed_rows' => $result->processedRows, 'accepted_rows' => $result->acceptedRows, 'rejected_rows' => $result->rejectedRows]);
            return $result;
        } catch (LeaseLostException|AuthException|ModuleException|TaskJobException $exception) {
            throw $exception;
        } catch (RetryableTaskException $exception) {
            $execution->assertLeaseOwned();
            $current = $this->repository->get($operation->tenantId, $operation->operationKey);
            $checkpoint = $this->checkpointProgressOrCancel(
                $execution,
                $operation->tenantId,
                $operation->id,
                $jobKey,
                $attempt,
                $current->processedRows,
                $current->acceptedRows,
                $current->rejectedRows,
            );
            if ($checkpoint->status === 'cancelled') {
                $this->audit($execution, $context, $checkpoint, 'cancelled', ['processed_rows' => $checkpoint->processedRows]);
                return $checkpoint;
            }
            if ($attempt >= 3) {
                $execution->assertLeaseOwned();
                $failed = $this->finish($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, $current->processedRows, $exception->safeCode);
                $this->audit($execution, $context, $failed, $failed->status, $failed->status === 'cancelled' ? ['processed_rows' => $failed->processedRows] : ['error_code' => $exception->safeCode]);
                if ($failed->status === 'cancelled') {
                    return $failed;
                }
            }
            throw $exception;
        } catch (ImportExportException $exception) {
            $execution->assertLeaseOwned();
            if (!in_array($exception->problemCode, ['IMPORT_EXPORT_STATE_CONFLICT', 'IMPORT_EXPORT_PERMISSION_DENIED'], true)) {
                $current = $this->repository->get($operation->tenantId, $operation->operationKey);
                $execution->assertLeaseOwned();
                $failed = $this->finish($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, $current->processedRows, $exception->problemCode);
                $this->audit($execution, $context, $failed, $failed->status, $failed->status === 'cancelled' ? ['processed_rows' => $failed->processedRows] : ['error_code' => $exception->problemCode]);
                if ($failed->status === 'cancelled') {
                    return $failed;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            // 保留内部错误位置供运维诊断；不写入异常消息、参数、令牌或原始数据。
            try {
                \think\facade\Log::record([
                    'event' => 'import_export.unexpected_failure',
                    'tenant_id' => $operation->tenantId,
                    'operation_key' => $operation->operationKey,
                    'exception_class' => $exception::class,
                    'file' => basename($exception->getFile()),
                    'line' => $exception->getLine(),
                    'frames' => array_map(static fn(array $frame): array => [
                        'class' => $frame['class'] ?? null,
                        'function' => $frame['function'] ?? null,
                        'file' => isset($frame['file']) ? basename($frame['file']) : null,
                        'line' => $frame['line'] ?? null,
                    ], array_slice($exception->getTrace(), 0, 8)),
                ], 'error');
            } catch (Throwable) {
                // 诊断失败不能替换原始业务异常或阻止状态恢复。
            }
            $execution->assertLeaseOwned();
            $current = $this->repository->get($operation->tenantId, $operation->operationKey);
            $execution->assertLeaseOwned();
            $failed = $this->finish($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, $current->processedRows, 'IMPORT_EXPORT_INTERNAL_ERROR');
            $this->audit($execution, $context, $failed, $failed->status, $failed->status === 'cancelled' ? ['processed_rows' => $failed->processedRows] : ['error_code' => 'IMPORT_EXPORT_INTERNAL_ERROR']);
            if ($failed->status === 'cancelled') {
                return $failed;
            }
            throw $exception;
        }
    }

    private function runImport(AuthorizedOperationContext $context, OperationRecord $operation, JobExecution $execution): OperationRecord
    {
        $jobKey = $execution->jobKey;
        $attempt = $execution->attemptNumber;
        $execution->checkpoint();
        if ($operation->inputFileKey === null) {
            throw ImportExportException::internal();
        }
        $stream = $this->files->openCsvInput($context, $operation->inputFileKey);
        $execution->assertLeaseOwned();
        if (!is_resource($stream)) {
            throw ImportExportException::fileUnavailable();
        }
        $provider = $this->providers->require($operation->providerKey);
        $schema = $provider->schema();
        $headings = fgetcsv($stream, 1048577, ',', '"', '');
        if (!is_array($headings) || count($headings) > 100) {
            throw ImportExportException::schemaMismatch();
        }
        $headings = array_map(static fn(mixed $value): string => is_string($value) && preg_match('//u', $value) === 1 ? $value : throw ImportExportException::schemaMismatch(), $headings);
        if (count(array_unique($headings, SORT_STRING)) !== count($headings) || array_diff(array_keys($operation->mapping), $headings) !== []) {
            throw ImportExportException::schemaMismatch();
        }
        $processed = $accepted = $rejected = $issueCount = 0;
        while (($values = fgetcsv($stream, 1048577, ',', '"', '')) !== false) {
            $execution->checkpoint();
            $checkpoint = $this->checkpointProgressOrCancel($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $accepted, $rejected);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            ++$processed;
            $rowNumber = $processed + 1;
            if ($processed > self::MAX_ROWS) {
                throw ImportExportException::limitExceeded();
            }
            $normalized = $schema->normalizeImportRow($values, $headings, $operation->mapping);
            $rowBytes = array_sum(array_map(static fn(mixed $value): int => is_string($value) ? strlen($value) : 0, $values));
            if ($rowBytes > 1048576) {
                $normalized['issues'][] = new RowIssue('IMPORT_ROW_TOO_LARGE');
            }
            $issues = $normalized['issues'];
            if ($issues === []) {
                $providerIssues = $provider->validateImport($context, $normalized['row']);
                foreach ($providerIssues as $issue) {
                    $issues[] = $issue;
                }
            }
            if ($issues !== []) {
                ++$rejected;
                $issueCount += count($issues);
                if ($issueCount > 10000) {
                    throw ImportExportException::limitExceeded();
                }
                foreach ($issues as $issue) {
                    $this->addRowIssue($execution, $operation->tenantId, $operation->id, $rowNumber, $issue);
                }
            } else {
                $execution->checkpoint();
                $provider->importRow($context, $normalized['row'], $operation->operationKey . ':row:' . $rowNumber);
                $execution->assertLeaseOwned();
                ++$accepted;
            }
            $checkpoint = $this->checkpointProgressOrCancel($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $accepted, $rejected);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            $this->auditProgress($execution, $context, $checkpoint);
        }
        $execution->checkpoint();
        $errorFile = $rejected > 0 ? $this->storeErrorReport($execution, $context, $operation) : null;
        $execution->assertLeaseOwned();
        return $this->finish($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, 'succeeded', null, $errorFile, $processed);
    }

    private function runExport(AuthorizedOperationContext $context, OperationRecord $operation, JobExecution $execution): OperationRecord
    {
        $jobKey = $execution->jobKey;
        $attempt = $execution->attemptNumber;
        $provider = $this->providers->require($operation->providerKey);
        $schema = $provider->schema();
        $stream = fopen('php://temp/maxmemory:' . self::MAX_BYTES, 'w+b');
        if (!is_resource($stream)) {
            throw ImportExportException::internal();
        }
        fputcsv($stream, array_map(static fn($column): string => self::csvSafe($column->heading), $schema->exportColumns()), ',', '"', '');
        $cursor = null;
        $processed = 0;
        do {
            $execution->checkpoint();
            $checkpoint = $this->checkpointProgressOrCancel($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $processed, 0);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            $execution->checkpoint();
            $batch = $provider->exportBatch($context, $cursor, 500);
            $execution->assertLeaseOwned();
            if (count($batch->rows) > 500 || ($batch->rows === [] && $batch->nextCursor !== null) || ($batch->nextCursor !== null && $batch->nextCursor === $cursor)) {
                throw ImportExportException::internal();
            }
            foreach ($batch->rows as $row) {
                ++$processed;
                if ($processed > self::MAX_ROWS) {
                    throw ImportExportException::limitExceeded();
                }
                fputcsv($stream, array_map(self::csvSafe(...), $schema->exportValues($row)), ',', '"', '');
                $size = ftell($stream);
                if (!is_int($size) || $size > self::MAX_BYTES) {
                    throw ImportExportException::limitExceeded();
                }
            }
            $checkpoint = $this->checkpointProgressOrCancel($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $processed, 0);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            $this->auditProgress($execution, $context, $checkpoint);
            $cursor = $batch->nextCursor;
        } while ($cursor !== null);
        rewind($stream);
        $execution->checkpoint();
        $fileKey = $this->files->storePrivateCsv($context, $operation->operationKey, 'result', $operation->providerKey . '-export.csv', $stream);
        $execution->assertLeaseOwned();
        self::assertFileKey($fileKey);
        $execution->assertLeaseOwned();
        return $this->finish($execution, $operation->tenantId, $operation->id, $jobKey, $attempt, 'succeeded', $fileKey, null, $processed);
    }

    private function storeErrorReport(JobExecution $execution, AuthorizedOperationContext $context, OperationRecord $operation): string
    {
        $stream = fopen('php://temp/maxmemory:' . self::MAX_BYTES, 'w+b');
        if (!is_resource($stream)) {
            throw ImportExportException::internal();
        }
        fputcsv($stream, ['row_number', 'column_key', 'error_code'], ',', '"', '');
        foreach ($this->repository->rowIssues($operation->tenantId, $operation->id) as $issue) {
            fputcsv($stream, [$issue['row_number'], $issue['column_key'] ?? '', $issue['error_code']], ',', '"', '');
        }
        rewind($stream);
        $execution->assertLeaseOwned();
        $fileKey = $this->files->storePrivateCsv($context, $operation->operationKey, 'errors', 'import-errors.csv', $stream);
        $execution->assertLeaseOwned();
        self::assertFileKey($fileKey);
        return $fileKey;
    }

    private function beginAttempt(JobExecution $execution, int $tenantId, string $operationKey, string $jobKey, int $attempt): OperationRecord
    {
        return Db::transaction(function () use ($execution, $tenantId, $operationKey, $jobKey, $attempt): OperationRecord {
            $execution->assertLeaseOwned();
            $result = $this->repository->beginAttempt($tenantId, $operationKey, $jobKey, $attempt);
            $execution->assertLeaseOwned();
            return $result;
        });
    }

    private function checkpointProgressOrCancel(
        JobExecution $execution,
        int $tenantId,
        int $operationId,
        string $jobKey,
        int $attempt,
        int $processed,
        int $accepted,
        int $rejected,
    ): OperationRecord {
        return Db::transaction(function () use ($execution, $tenantId, $operationId, $jobKey, $attempt, $processed, $accepted, $rejected): OperationRecord {
            $execution->assertLeaseOwned();
            $result = $this->repository->checkpointProgressOrCancel(
                $tenantId,
                $operationId,
                $jobKey,
                $attempt,
                $processed,
                $accepted,
                $rejected,
            );
            $execution->assertLeaseOwned();
            return $result;
        });
    }

    private function addRowIssue(JobExecution $execution, int $tenantId, int $operationId, int $rowNumber, RowIssue $issue): void
    {
        Db::transaction(function () use ($execution, $tenantId, $operationId, $rowNumber, $issue): void {
            $execution->assertLeaseOwned();
            $this->repository->addRowIssue($tenantId, $operationId, $rowNumber, $issue);
            $execution->assertLeaseOwned();
        });
    }

    private function finish(
        JobExecution $execution,
        int $tenantId,
        int $operationId,
        string $jobKey,
        int $attempt,
        string $status,
        ?string $resultFileKey,
        ?string $errorFileKey,
        int $totalRows,
        ?string $errorCode = null,
    ): OperationRecord {
        return Db::transaction(function () use ($execution, $tenantId, $operationId, $jobKey, $attempt, $status, $resultFileKey, $errorFileKey, $totalRows, $errorCode): OperationRecord {
            $execution->assertLeaseOwned();
            $result = $this->repository->finish(
                $tenantId,
                $operationId,
                $jobKey,
                $attempt,
                $status,
                $resultFileKey,
                $errorFileKey,
                $totalRows,
                $errorCode,
            );
            $execution->assertLeaseOwned();
            return $result;
        });
    }

    private static function assertFileKey(string $key): void
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $key) !== 1) {
            throw ImportExportException::fileUnavailable();
        }
    }

    private static function csvSafe(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw ImportExportException::schemaMismatch();
        }
        return preg_match('/^(?:\xEF\xBB\xBF)?[\x09\x0B\x0C\x0D\x20]*[=+@-]/', $value) === 1 ? "'" . $value : $value;
    }

    private function auditProgress(JobExecution $execution, AuthorizedOperationContext $context, OperationRecord $operation): void
    {
        if ($operation->processedRows !== 1 && $operation->processedRows % 1000 !== 0) {
            return;
        }
        $this->audit($execution, $context, $operation, 'progress', [
            'processed_rows' => $operation->processedRows,
            'accepted_rows' => $operation->acceptedRows,
            'rejected_rows' => $operation->rejectedRows,
        ]);
    }

    /** @param array<string, int|string> $metadata */
    private function audit(JobExecution $execution, AuthorizedOperationContext $context, OperationRecord $operation, string $event, array $metadata): void
    {
        Db::transaction(function () use ($execution, $context, $operation, $event, $metadata): void {
            $execution->assertLeaseOwned();
            $this->audit->tenantMember(
                $context->tenantContext,
                'tenant.import_export.' . $event,
                'peanut.import-export.execute',
                'import_export_operation',
                $operation->operationKey,
                targetCount: 1,
                metadata: ['direction' => $operation->direction, 'provider_key' => $operation->providerKey, 'revision' => $operation->revision] + $metadata,
            );
            $execution->assertLeaseOwned();
        });
    }
}
