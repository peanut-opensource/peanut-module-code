<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Ops\Contract\BackupTaskExecution;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use app\platform\value\ops\PairedBackupManifest;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Modules\Ops\Domain\Package;
use RuntimeException;
use think\facade\Db;

/** Trusted deployment-worker boundary; no HTTP controller calls this service. */
final readonly class ThinkPhpBackupTaskExecutionService implements BackupTaskExecution
{
    private const FAILURE_CODES = [
        'OPS_BACKUP_CAPACITY_INSUFFICIENT',
        'OPS_BACKUP_QUIESCENCE_FAILED',
        'OPS_BACKUP_DATABASE_FAILED',
        'OPS_BACKUP_FILES_FAILED',
        'OPS_BACKUP_MANIFEST_INVALID',
        'OPS_BACKUP_INTEGRITY_FAILED',
        'OPS_BACKUP_RUNTIME_FAILED',
    ];

    public function __construct(
        private AuditContractHost $audit,
        private PlatformOperatorIdentityQuery $operators,
    ) {}

    /** @return array{task_key:string,backup_reference_key:string,provider_key:string,execution_revision:int}|null */
    public function claim(): ?array
    {
        return $this->transaction(function (): ?array {
            $this->failStaleRunningTasks();
            $row = Db::name('ops_task')->where('task_type', Package::BACKUP_TASK_TYPE)
                ->where('handler_key', PairedBackupProvider::BACKUP_HANDLER_KEY)->where('status', 'queued')
                ->where('available_at', '<=', Db::raw('UTC_TIMESTAMP(3)'))->field('id,task_key,payload_json,attempt_count,max_attempts,revision')
                ->order('id')->lock('FOR UPDATE SKIP LOCKED')->find();
            if (!is_array($row)) {
                return null;
            }

            $payload = json_decode((string) $row['payload_json'], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || array_keys($payload) !== ['provider_key']
                || ($payload['provider_key'] ?? null) !== PairedBackupProvider::PROVIDER_KEY
                || (int) $row['attempt_count'] >= (int) $row['max_attempts']
            ) {
                throw new RuntimeException('OPS_BACKUP_TASK_INVALID');
            }

            $updated = Db::name('ops_task')->where('id', $row['id'])->where('status', 'queued')
                ->where('revision', $row['revision'])->update([
                    'status' => 'running', 'attempt_count' => Db::raw('attempt_count + 1'),
                    'revision' => Db::raw('revision + 1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('OPS_BACKUP_TASK_CLAIM_CONFLICT');
            }

            $taskKey = (string) $row['task_key'];
            return [
                'task_key' => $taskKey,
                'backup_reference_key' => 'backup_' . substr($taskKey, 4),
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                'execution_revision' => (int) $row['revision'] + 1,
            ];
        });
    }

    /** @return array{task_key:string,status:string,execution_revision:int} */
    public function heartbeat(string $taskKey, int $executionRevision): array
    {
        $this->taskSuffix($taskKey);
        $this->assertExecutionRevision($executionRevision);

        return $this->transaction(function () use ($taskKey, $executionRevision): array {
            $task = $this->taskForUpdate($taskKey);
            if ((string) $task['status'] !== 'running'
                || (int) $task['revision'] !== $executionRevision
            ) {
                throw new RuntimeException('OPS_BACKUP_EXECUTION_FENCED');
            }
            Db::name('ops_task')->where('task_key', $taskKey)->where('status', 'running')
                ->where('revision', $executionRevision)->update(['updated_at' => Db::raw('UTC_TIMESTAMP(3)')]);
            return [
                'task_key' => $taskKey,
                'status' => 'running',
                'execution_revision' => $executionRevision,
            ];
        });
    }

    private function failStaleRunningTasks(): void
    {
        $tasks = Db::name('ops_task')->alias('task')
            ->where('task.task_type', Package::BACKUP_TASK_TYPE)->where('task.handler_key', PairedBackupProvider::BACKUP_HANDLER_KEY)
            ->where('task.status', 'running')->where('task.updated_at', '<', Db::raw('TIMESTAMPADD(HOUR, -2, UTC_TIMESTAMP(3))'))
            ->field('task.*')->order('task.id')->lock(true)->select()->toArray();
        foreach ($tasks as $task) {
            $updated = Db::name('ops_task')->where('id', $task['id'])->where('status', 'running')->update([
                'status' => 'dead', 'revision' => Db::raw('revision + 1'),
                'last_error_code' => 'OPS_BACKUP_RUNTIME_FAILED', 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
            if ($updated === 1) {
                $this->audit($task, 'platform.ops.backup.failed', 'backup.fail', [
                    'task_key' => (string) $task['task_key'],
                    'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                ], AuditOutcome::Error, 'OPS_BACKUP_RUNTIME_FAILED');
            }
        }
    }

    /** @return array{task_key:string,backup_reference_key:string,manifest_sha256:string,status:string} */
    public function succeed(string $taskKey, int $executionRevision, string $manifestJson): array
    {
        $this->assertExecutionRevision($executionRevision);
        $manifest = PairedBackupManifest::fromJson($manifestJson);
        $canonical = $manifest->canonicalJson();
        $manifestArray = $manifest->toArray();
        $expectedReference = 'backup_' . $this->taskSuffix($taskKey);
        if (!hash_equals($expectedReference, $manifest->backupReferenceKey())) {
            throw new RuntimeException('OPS_BACKUP_REFERENCE_MISMATCH');
        }

        return $this->transaction(function () use ($taskKey, $executionRevision, $manifest, $manifestArray, $canonical): array {
            $task = $this->taskForUpdate($taskKey);
            $existing = $this->evidence($taskKey);
            $sha256 = hash('sha256', $canonical);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['manifest_sha256'], $sha256)) {
                    throw new RuntimeException('OPS_BACKUP_EVIDENCE_CONFLICT');
                }
                return [
                    'task_key' => $taskKey,
                    'backup_reference_key' => $manifest->backupReferenceKey(),
                    'manifest_sha256' => $sha256,
                    'status' => 'succeeded',
                ];
            }
            if ((string) $task['status'] !== 'running'
                || (int) $task['revision'] !== $executionRevision
            ) {
                throw new RuntimeException('OPS_BACKUP_EXECUTION_FENCED');
            }

            $source = $manifestArray['source'];
            $window = $manifestArray['consistency_window'];
            Db::name('ops_backup_evidence')->insert([
                'backup_reference_key' => $manifest->backupReferenceKey(),
                'task_key' => $taskKey,
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
                'manifest_sha256' => $sha256,
                'source_commit' => $source['commit'],
                'source_tree' => $source['tree'],
                'source_release_key' => $source['release_key'],
                'consistency_started_at' => $this->databaseInstant($window['started_at']),
                'consistency_completed_at' => $this->databaseInstant($window['completed_at']),
                'verified_at' => Db::raw('UTC_TIMESTAMP(3)'),
                'manifest_json' => $canonical,
            ]);

            $updated = Db::name('ops_task')->where('task_key', $taskKey)->where('status', 'running')
                ->where('revision', $executionRevision)->update([
                    'status' => 'succeeded', 'revision' => Db::raw('revision + 1'), 'last_error_code' => null,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'), 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('OPS_BACKUP_TASK_STATE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.backup.succeeded', 'backup.succeed', [
                'task_key' => $taskKey,
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
            ], AuditOutcome::Success, null);

            return [
                'task_key' => $taskKey,
                'backup_reference_key' => $manifest->backupReferenceKey(),
                'manifest_sha256' => $sha256,
                'status' => 'succeeded',
            ];
        });
    }

    /** @return array{task_key:string,status:string,last_error_code:string} */
    public function fail(string $taskKey, int $executionRevision, string $errorCode): array
    {
        if (!in_array($errorCode, self::FAILURE_CODES, true)) {
            throw new RuntimeException('OPS_BACKUP_FAILURE_CODE_INVALID');
        }
        $this->taskSuffix($taskKey);
        $this->assertExecutionRevision($executionRevision);

        return $this->transaction(function () use ($taskKey, $executionRevision, $errorCode): array {
            $task = $this->taskForUpdate($taskKey);
            if ((string) $task['status'] === 'dead' && hash_equals((string) $task['last_error_code'], $errorCode)) {
                return ['task_key' => $taskKey, 'status' => 'dead', 'last_error_code' => $errorCode];
            }
            if ((string) $task['status'] !== 'running'
                || (int) $task['revision'] !== $executionRevision
            ) {
                throw new RuntimeException('OPS_BACKUP_EXECUTION_FENCED');
            }
            $updated = Db::name('ops_task')->where('task_key', $taskKey)->where('status', 'running')
                ->where('revision', $executionRevision)->update([
                    'status' => 'dead', 'revision' => Db::raw('revision + 1'), 'last_error_code' => $errorCode,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'), 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('OPS_BACKUP_TASK_STATE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.backup.failed', 'backup.fail', [
                'task_key' => $taskKey,
                'provider_key' => PairedBackupProvider::PROVIDER_KEY,
            ], AuditOutcome::Error, $errorCode);
            return ['task_key' => $taskKey, 'status' => 'dead', 'last_error_code' => $errorCode];
        });
    }

    /** @return array<string,mixed> */
    private function taskForUpdate(string $taskKey): array
    {
        $row = Db::name('ops_task')->alias('task')
            ->where('task.task_key', $taskKey)->where('task.task_type', Package::BACKUP_TASK_TYPE)
            ->where('task.handler_key', PairedBackupProvider::BACKUP_HANDLER_KEY)
            ->field('task.*')->lock(true)->find();
        if (!is_array($row)) {
            throw new RuntimeException('OPS_BACKUP_TASK_NOT_FOUND');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function evidence(string $taskKey): ?array
    {
        $row = Db::name('ops_backup_evidence')->where('task_key', $taskKey)->field('manifest_sha256')->lock(true)->find();
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

    private function taskSuffix(string $taskKey): string
    {
        if (preg_match('/^job_([a-f0-9]{32})$/D', $taskKey, $matches) !== 1) {
            throw new RuntimeException('OPS_BACKUP_TASK_KEY_INVALID');
        }
        return $matches[1];
    }

    private function databaseInstant(string $instant): string
    {
        return str_replace(['T', 'Z'], [' ', ''], $instant);
    }

    private function assertExecutionRevision(int $executionRevision): void
    {
        if ($executionRevision < 2) {
            throw new RuntimeException('OPS_BACKUP_EXECUTION_REVISION_INVALID');
        }
    }

    private function transaction(callable $operation): mixed
    {
        return Db::transaction($operation);
    }
}
