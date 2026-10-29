<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Ops\Service\PlatformModuleOperationExecutionService;
use PeanutAdmin\Modules\Ops\Service\PlatformUpgradeExecutionService;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTask;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskDispatcher;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskSubmission;
use think\facade\Db;

/** Application persistence adapter for Core operations tasks. */
final readonly class ThinkPhpOpsTaskDispatcher implements OpsTaskDispatcher
{
    public function __construct(
        private AuditContractHost $audit,
    ) {}

    public function dispatch(PlatformContext $context, OpsTaskSubmission $submission): OpsTask
    {
        return $this->map($this->dispatchRow(
            $context,
            $submission->taskType,
            $submission->handlerKey,
            $submission->payload,
            $submission->idempotencyDigest,
            $submission->requestDigest,
            $submission->concurrencyKey,
            $submission->maximumAttempts,
            $submission->audit->eventType,
            $submission->audit->action,
            $submission->audit->metadata,
        ));
    }

    /**
     * Application-owned PC42 extension over the same canonical task ledger.
     *
     * @param array<string,string> $payload
     * @return array<string,mixed>
     */
    public function dispatchUpgrade(
        PlatformContext $context,
        array $payload,
        string $idempotencyKey,
    ): array {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200
            || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1
        ) {
            throw OpsConsoleException::invalid();
        }
        $idempotencyDigest = hash('sha256', $idempotencyKey);
        $requestDigest = hash('sha256', json_encode(
            ['task_type' => PlatformUpgradeExecutionService::TASK_TYPE, 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return $this->dispatchRow(
            $context,
            PlatformUpgradeExecutionService::TASK_TYPE,
            PlatformUpgradeExecutionService::HANDLER_KEY,
            $payload,
            $idempotencyDigest,
            $requestDigest,
            PlatformUpgradeExecutionService::CONCURRENCY_KEY,
            1,
            'platform.ops.upgrade.submitted',
            'upgrade.submit',
            [
                'target_release_key' => $payload['target_release_key'],
                'target_commit' => $payload['target_commit'],
                'target_descriptor_sha256' => $payload['target_descriptor_sha256'],
                'idempotency_digest' => $idempotencyDigest,
                'request_digest' => $requestDigest,
            ],
        );
    }

    /**
     * @param array<string,string> $payload
     * @return array<string,mixed>
     */
    public function dispatchModuleOperation(
        PlatformContext $context,
        array $payload,
        string $idempotencyKey,
    ): array {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200
            || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1
        ) {
            throw OpsConsoleException::invalid();
        }
        $idempotencyDigest = hash('sha256', $idempotencyKey);
        $requestDigest = hash('sha256', json_encode(
            ['task_type' => PlatformModuleOperationExecutionService::TASK_TYPE, 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        return $this->dispatchRow(
            $context,
            PlatformModuleOperationExecutionService::TASK_TYPE,
            PlatformModuleOperationExecutionService::HANDLER_KEY,
            $payload,
            $idempotencyDigest,
            $requestDigest,
            $payload['environment'] === 'development'
                ? 'ops.module.execute.development.' . hash('sha256', $payload['application_root'])
                : PlatformModuleOperationExecutionService::CONCURRENCY_KEY,
            1,
            'platform.ops.module.submitted',
            'module.submit',
            [
                'request_key' => $payload['request_key'],
                'environment' => $payload['environment'],
                'target_resource_id' => $payload['target_resource_id'],
                'package_key' => $payload['package_key'],
                'operation' => $payload['operation'],
                'idempotency_digest' => $idempotencyDigest,
                'request_digest' => $requestDigest,
            ],
        );
    }

    /**
     * @param array<string,string> $payload
     * @param array<string,bool|int|string|null> $auditMetadata
     * @return array<string,mixed>
     */
    private function dispatchRow(
        PlatformContext $context,
        string $taskType,
        string $handlerKey,
        array $payload,
        string $idempotencyDigest,
        string $requestDigest,
        string $concurrencyKey,
        int $maximumAttempts,
        string $eventType,
        string $action,
        array $auditMetadata,
    ): array {
        return Db::transaction(function () use (
            $context,
            $taskType,
            $handlerKey,
            $payload,
            $idempotencyDigest,
            $requestDigest,
            $concurrencyKey,
            $maximumAttempts,
            $eventType,
            $action,
            $auditMetadata
        ): array {
            $existing = Db::name('ops_task')->where('submitted_by_operator_id', $context->operatorId)
                ->where('idempotency_digest', $idempotencyDigest)->lock(true)->find();
            if ($existing !== null) {
                if (!hash_equals((string) $existing['request_digest'], $requestDigest)) {
                    throw OpsConsoleException::idempotencyConflict();
                }
                return $existing;
            }

            $active = Db::name('ops_task')->where('concurrency_key', $concurrencyKey)
                ->whereIn('status', ['queued', 'running'])->field('id')->lock(true)->find();
            if ($active !== null) {
                throw OpsConsoleException::operationInProgress();
            }

            $taskKey = 'job_' . bin2hex(random_bytes(16));
            if ($taskType === \PeanutAdmin\Modules\Ops\Domain\Package::RESTORE_TASK_TYPE) {
                $backup = Db::name('ops_backup_evidence')
                    ->where('backup_reference_key', $payload['backup_reference_key'])
                    ->field('manifest_json')->lock(true)->find();
                if (is_array($backup)) {
                    $historical = json_decode((string) $backup['manifest_json'], true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($historical) && in_array($historical['schema_version'] ?? null, [2, 3], true)) {
                        throw OpsConsoleException::restoreUnsupportedSchema();
                    }
                }
            }
            Db::name('ops_task')->insert([
                'task_key' => $taskKey,
                'task_type' => $taskType,
                'handler_key' => $handlerKey,
                'payload_json' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                'status' => 'queued',
                'attempt_count' => 0,
                'max_attempts' => $maximumAttempts,
                'revision' => 1,
                'last_error_code' => null,
                'idempotency_digest' => $idempotencyDigest,
                'request_digest' => $requestDigest,
                'concurrency_key' => $concurrencyKey,
                'submitted_by_operator_id' => $context->operatorId,
                'available_at' => Db::raw('UTC_TIMESTAMP(3)'),
                'created_at' => Db::raw('UTC_TIMESTAMP(3)'),
                'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                'completed_at' => null,
            ]);

            $this->audit->recordPlatform(
                $eventType,
                $action,
                $context->requestId,
                $context->operatorId,
                $context->accountId,
                [...$auditMetadata, 'task_key' => $taskKey],
                AuditOutcome::Success,
                null,
            );

            $row = Db::name('ops_task')->where('task_key', $taskKey)->find();
            if ($row === null) {
                throw OpsConsoleException::taskUnavailable();
            }
            return $row;
        });
    }

    public function find(PlatformContext $context, string $taskKey): OpsTask
    {
        $row = Db::name('ops_task')->where('task_key', $taskKey)->find();
        if ($row === null) {
            throw OpsConsoleException::taskNotFound();
        }
        return $this->map($row);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): OpsTask
    {
        return new OpsTask(
            (string) $row['task_key'],
            (string) $row['task_type'],
            (string) $row['status'],
            (int) $row['attempt_count'],
            (int) $row['max_attempts'],
            (int) $row['revision'],
            $row['last_error_code'] === null ? null : (string) $row['last_error_code'],
            $this->instant((string) $row['available_at']),
            $this->instant((string) $row['created_at']),
            $this->instant((string) $row['updated_at']),
            $row['completed_at'] === null ? null : $this->instant((string) $row['completed_at']),
        );
    }

    private function instant(string $value): string
    {
        $normalized = str_replace(' ', 'T', trim($value));
        if (!str_contains($normalized, '.')) {
            $normalized .= '.000';
        }
        return $normalized . 'Z';
    }
}
