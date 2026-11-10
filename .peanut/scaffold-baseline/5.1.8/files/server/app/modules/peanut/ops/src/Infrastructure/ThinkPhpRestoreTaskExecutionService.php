<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Ops\Contract\RestoreTaskExecution;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use app\platform\value\ops\PairedBackupManifest;
use app\platform\value\ops\RestoreVerificationEvidence;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Modules\Ops\Domain\Package;
use RuntimeException;
use think\facade\Db;

/** Trusted deployment-worker boundary for isolated restore verification. */
final readonly class ThinkPhpRestoreTaskExecutionService implements RestoreTaskExecution
{
    private const FAILURE_CODES = [
        'OPS_RESTORE_BACKUP_NOT_FOUND',
        'OPS_RESTORE_UNSUPPORTED_SCHEMA',
        'OPS_RESTORE_ARTIFACT_INVALID',
        'OPS_RESTORE_TARGET_NOT_EMPTY',
        'OPS_RESTORE_ISOLATION_VIOLATION',
        'OPS_RESTORE_DATABASE_FAILED',
        'OPS_RESTORE_FILES_FAILED',
        'OPS_RESTORE_SCHEMA_INVALID',
        'OPS_RESTORE_DATA_INVALID',
        'OPS_RESTORE_CLEANUP_FAILED',
        'OPS_RESTORE_RUNTIME_FAILED',
    ];

    public function __construct(
        private AuditContractHost $audit,
        private PlatformOperatorIdentityQuery $operators,
    ) {}

    /** @return array<string,mixed>|null */
    public function claim(): ?array
    {
        return $this->transaction(function (): ?array {
            $this->failStaleRunningTasks();
            $row = Db::name('ops_task')->where('task_type', Package::RESTORE_TASK_TYPE)
                ->where('handler_key', PairedBackupProvider::RESTORE_HANDLER_KEY)->where('status', 'queued')
                ->where('available_at', '<=', Db::raw('UTC_TIMESTAMP(3)'))->field('id,task_key,payload_json,attempt_count,max_attempts,revision')
                ->order('id')->lock('FOR UPDATE SKIP LOCKED')->find();
            if (!is_array($row)) {
                return null;
            }

            $payload = json_decode((string) $row['payload_json'], true, 8, JSON_THROW_ON_ERROR);
            $payloadKeys = is_array($payload) ? array_keys($payload) : [];
            sort($payloadKeys, SORT_STRING);
            if (!is_array($payload)
                || $payloadKeys !== ['backup_reference_key', 'provider_key', 'target_key']
                || ($payload['provider_key'] ?? null) !== PairedBackupProvider::PROVIDER_KEY
                || ($payload['target_key'] ?? null) !== PairedBackupProvider::RESTORE_TARGET_KEY
                || !is_string($payload['backup_reference_key'] ?? null)
                || preg_match('/^backup_[a-f0-9]{32}$/D', $payload['backup_reference_key']) !== 1
                || (int) $row['attempt_count'] >= (int) $row['max_attempts']
            ) {
                throw new RuntimeException('OPS_RESTORE_TASK_INVALID');
            }
            $backup = $this->backupEvidence($payload['backup_reference_key']);

            $updated = Db::name('ops_task')->where('id', $row['id'])->where('status', 'queued')
                ->where('revision', $row['revision'])->update([
                    'status' => 'running', 'attempt_count' => Db::raw('attempt_count + 1'),
                    'revision' => Db::raw('revision + 1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('OPS_RESTORE_TASK_CLAIM_CONFLICT');
            }

            return [
                'task_key' => (string) $row['task_key'],
                'backup_reference_key' => $payload['backup_reference_key'],
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                'target_key' => PairedBackupProvider::RESTORE_TARGET_KEY,
                'manifest_sha256' => (string) $backup['manifest_sha256'],
                'source_commit' => (string) $backup['source_commit'],
                'source_tree' => (string) $backup['source_tree'],
                'execution_revision' => (int) $row['revision'] + 1,
            ];
        });
    }

    /** @return array<string,string|int> */
    public function verifyManifest(string $taskKey, int $executionRevision, string $manifestJson): array
    {
        $this->assertExecutionRevision($executionRevision);
        $manifest = PairedBackupManifest::fromJson($manifestJson);
        return $this->transaction(function () use ($taskKey, $executionRevision, $manifest): array {
            $task = $this->taskForUpdate($taskKey);
            $payload = $this->payload($task);
            if ((string) $task['status'] !== 'running' || (int) $task['revision'] !== $executionRevision) {
                throw new RuntimeException('OPS_RESTORE_EXECUTION_FENCED');
            }
            if (!hash_equals($payload['backup_reference_key'], $manifest->backupReferenceKey())) {
                throw new RuntimeException('OPS_RESTORE_ARTIFACT_INVALID');
            }
            $backup = $this->backupEvidence($payload['backup_reference_key']);
            $manifestSha256 = hash('sha256', $manifest->canonicalJson());
            if (!hash_equals((string) $backup['manifest_sha256'], $manifestSha256)) {
                throw new RuntimeException('OPS_RESTORE_ARTIFACT_INVALID');
            }
            return [
                'backup_reference_key' => $payload['backup_reference_key'],
                'manifest_sha256' => $manifestSha256,
                'source_commit' => (string) $backup['source_commit'],
                'source_tree' => (string) $backup['source_tree'],
                'execution_revision' => $executionRevision,
            ];
        });
    }

    /** @return array{task_key:string,status:string,execution_revision:int} */
    public function heartbeat(string $taskKey, int $executionRevision): array
    {
        $this->assertExecutionRevision($executionRevision);
        return $this->transaction(function () use ($taskKey, $executionRevision): array {
            $task = $this->taskForUpdate($taskKey);
            if ((string) $task['status'] !== 'running' || (int) $task['revision'] !== $executionRevision) {
                throw new RuntimeException('OPS_RESTORE_EXECUTION_FENCED');
            }
            Db::name('ops_task')->where('task_key', $taskKey)->where('status', 'running')
                ->where('revision', $executionRevision)->update(['updated_at' => Db::raw('UTC_TIMESTAMP(3)')]);
            return ['task_key' => $taskKey, 'status' => 'running', 'execution_revision' => $executionRevision];
        });
    }

    /** @return array<string,string> */
    public function succeed(string $taskKey, int $executionRevision, string $evidenceJson): array
    {
        $this->assertExecutionRevision($executionRevision);
        $evidence = RestoreVerificationEvidence::fromJson($evidenceJson);
        $canonical = $evidence->canonicalJson();
        $data = $evidence->toArray();

        return $this->transaction(function () use ($taskKey, $executionRevision, $canonical, $data): array {
            $task = $this->taskForUpdate($taskKey);
            $payload = $this->payload($task);
            $existing = $this->restoreEvidence($taskKey);
            $evidenceSha256 = hash('sha256', $canonical);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['evidence_sha256'], $evidenceSha256)) {
                    throw new RuntimeException('OPS_RESTORE_EVIDENCE_CONFLICT');
                }
                return ['task_key' => $taskKey, 'status' => 'succeeded', 'evidence_sha256' => $evidenceSha256];
            }
            if ((string) $task['status'] !== 'running' || (int) $task['revision'] !== $executionRevision) {
                throw new RuntimeException('OPS_RESTORE_EXECUTION_FENCED');
            }
            $backup = $this->backupEvidence($payload['backup_reference_key']);
            if (!hash_equals($payload['backup_reference_key'], (string) $data['backup_reference_key'])
                || !hash_equals((string) $backup['manifest_sha256'], (string) $data['manifest_sha256'])
                || !hash_equals((string) $backup['source_commit'], (string) $data['source']['commit'])
                || !hash_equals((string) $backup['source_tree'], (string) $data['source']['tree'])
            ) {
                throw new RuntimeException('OPS_RESTORE_EVIDENCE_INVALID');
            }

            $verification = $data['verification'];
            $isolation = $data['isolation'];
            Db::name('ops_restore_evidence')->insert([
                'task_key' => $taskKey,
                'backup_reference_key' => $data['backup_reference_key'],
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                'target_key' => PairedBackupProvider::RESTORE_TARGET_KEY,
                'manifest_sha256' => $data['manifest_sha256'],
                'evidence_sha256' => $evidenceSha256,
                'source_commit' => $data['source']['commit'],
                'source_tree' => $data['source']['tree'],
                'target_deployment_resource_id' => $data['target']['deployment_resource_id'],
                'target_database_resource_id' => $data['target']['database_resource_id'],
                'target_runtime_resource_id' => $data['target']['runtime_resource_id'],
                'table_count' => $verification['table_count'],
                'schema_migration_count' => $verification['schema_migration_count'],
                'critical_table_count' => $verification['critical_table_count'],
                'account_count' => $verification['account_count'],
                'tenant_count' => $verification['tenant_count'],
                'tenant_member_count' => $verification['tenant_member_count'],
                'storage_file_count' => $verification['storage_file_count'],
                'storage_bytes' => $verification['storage_bytes'],
                'protected_runtime_sha256' => $isolation['protected_runtime_after_sha256'],
                'verified_at' => $this->databaseInstant($data['verified_at']),
                'evidence_json' => $canonical,
            ]);

            $updated = Db::name('ops_task')->where('task_key', $taskKey)->where('status', 'running')
                ->where('revision', $executionRevision)->update([
                    'status' => 'succeeded', 'revision' => Db::raw('revision + 1'), 'last_error_code' => null,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'), 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('OPS_RESTORE_TASK_STATE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.restore.succeeded', 'restore.succeed', [
                'task_key' => $taskKey,
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                'target_key' => PairedBackupProvider::RESTORE_TARGET_KEY,
            ], AuditOutcome::Success, null);
            return ['task_key' => $taskKey, 'status' => 'succeeded', 'evidence_sha256' => $evidenceSha256];
        });
    }

    /** @return array{task_key:string,status:string,last_error_code:string} */
    public function fail(string $taskKey, int $executionRevision, string $errorCode): array
    {
        if (!in_array($errorCode, self::FAILURE_CODES, true)) {
            throw new RuntimeException('OPS_RESTORE_FAILURE_CODE_INVALID');
        }
        $this->assertExecutionRevision($executionRevision);
        return $this->transaction(function () use ($taskKey, $executionRevision, $errorCode): array {
            $task = $this->taskForUpdate($taskKey);
            if ((string) $task['status'] === 'dead' && hash_equals((string) $task['last_error_code'], $errorCode)) {
                return ['task_key' => $taskKey, 'status' => 'dead', 'last_error_code' => $errorCode];
            }
            if ((string) $task['status'] !== 'running' || (int) $task['revision'] !== $executionRevision) {
                throw new RuntimeException('OPS_RESTORE_EXECUTION_FENCED');
            }
            $updated = Db::name('ops_task')->where('task_key', $taskKey)->where('status', 'running')
                ->where('revision', $executionRevision)->update([
                    'status' => 'dead', 'revision' => Db::raw('revision + 1'), 'last_error_code' => $errorCode,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'), 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('OPS_RESTORE_TASK_STATE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.restore.failed', 'restore.fail', [
                'task_key' => $taskKey,
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                'target_key' => PairedBackupProvider::RESTORE_TARGET_KEY,
            ], AuditOutcome::Error, $errorCode);
            return ['task_key' => $taskKey, 'status' => 'dead', 'last_error_code' => $errorCode];
        });
    }

    private function failStaleRunningTasks(): void
    {
        $tasks = Db::name('ops_task')->alias('task')
            ->where('task.task_type', Package::RESTORE_TASK_TYPE)->where('task.handler_key', PairedBackupProvider::RESTORE_HANDLER_KEY)
            ->where('task.status', 'running')->where('task.updated_at', '<', Db::raw('TIMESTAMPADD(HOUR, -2, UTC_TIMESTAMP(3))'))
            ->field('task.*')->order('task.id')->lock(true)->select()->toArray();
        foreach ($tasks as $task) {
            $updated = Db::name('ops_task')->where('id', $task['id'])->where('status', 'running')->update([
                'status' => 'dead', 'revision' => Db::raw('revision + 1'),
                'last_error_code' => 'OPS_RESTORE_RUNTIME_FAILED', 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
            if ($updated === 1) {
                $this->audit($task, 'platform.ops.restore.failed', 'restore.fail', [
                    'task_key' => (string) $task['task_key'],
                    'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                    'target_key' => PairedBackupProvider::RESTORE_TARGET_KEY,
                ], AuditOutcome::Error, 'OPS_RESTORE_RUNTIME_FAILED');
            }
        }
    }

    /** @return array<string,mixed> */
    private function taskForUpdate(string $taskKey): array
    {
        $row = Db::name('ops_task')->alias('task')
            ->where('task.task_key', $taskKey)->where('task.task_type', Package::RESTORE_TASK_TYPE)
            ->where('task.handler_key', PairedBackupProvider::RESTORE_HANDLER_KEY)
            ->field('task.*')->lock(true)->find();
        if (!is_array($row)) {
            throw new RuntimeException('OPS_RESTORE_TASK_NOT_FOUND');
        }
        return $row;
    }

    /** @param array<string,mixed> $task @return array{provider_key:string,backup_reference_key:string,target_key:string} */
    private function payload(array $task): array
    {
        $payload = json_decode((string) $task['payload_json'], true, 8, JSON_THROW_ON_ERROR);
        $payloadKeys = is_array($payload) ? array_keys($payload) : [];
        sort($payloadKeys, SORT_STRING);
        if (!is_array($payload) || $payloadKeys !== ['backup_reference_key', 'provider_key', 'target_key']) {
            throw new RuntimeException('OPS_RESTORE_TASK_INVALID');
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function backupEvidence(string $backupReferenceKey): array
    {
        $row = Db::name('ops_backup_evidence')->where('backup_reference_key', $backupReferenceKey)
            ->field('manifest_sha256,source_commit,source_tree,manifest_json')->lock(true)->find();
        if (!is_array($row)) {
            throw new RuntimeException('OPS_RESTORE_BACKUP_NOT_FOUND');
        }
        $historical = json_decode((string) $row['manifest_json'], true, 32, JSON_THROW_ON_ERROR);
        if (is_array($historical) && in_array($historical['schema_version'] ?? null, [2, 3], true)) {
            throw new RuntimeException('OPS_RESTORE_UNSUPPORTED_SCHEMA');
        }
        $manifest = PairedBackupManifest::fromJson((string) $row['manifest_json']);
        if (!hash_equals((string) $row['manifest_sha256'], hash('sha256', $manifest->canonicalJson()))
            || !hash_equals($backupReferenceKey, $manifest->backupReferenceKey())
        ) {
            throw new RuntimeException('OPS_RESTORE_ARTIFACT_INVALID');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function restoreEvidence(string $taskKey): ?array
    {
        $row = Db::name('ops_restore_evidence')->where('task_key', $taskKey)->field('evidence_sha256')->lock(true)->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $task @param array<string,string> $metadata */
    private function audit(
        array $task,
        string $eventType,
        string $action,
        array $metadata,
        AuditOutcome $outcome,
        ?string $reasonCode,
    ): void {
        $this->audit->recordPlatform(
            $eventType,
            $action,
            'ops-worker-' . bin2hex(random_bytes(16)),
            (int) $task['submitted_by_operator_id'],
            $this->operators->accountId((int) $task['submitted_by_operator_id']),
            $metadata,
            $outcome,
            $reasonCode,
        );
    }

    private function assertExecutionRevision(int $executionRevision): void
    {
        if ($executionRevision < 2) {
            throw new RuntimeException('OPS_RESTORE_EXECUTION_REVISION_INVALID');
        }
    }

    private function databaseInstant(string $instant): string
    {
        return str_replace(['T', 'Z'], [' ', ''], $instant);
    }

    private function transaction(callable $operation): mixed
    {
        return Db::transaction($operation);
    }
}
