<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Ops\Contract\UpgradeTaskExecution;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Ops\Service\PlatformUpgradeExecutionService;
use app\platform\value\ops\PlatformUpgradeTarget;
use app\common\services\audit\AuditContractHost;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceWindow;
use PeanutAdmin\Modules\Ops\Domain\Package;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsAuditEvent;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskSubmission;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProviderRegistry;
use think\facade\Db;

/** Trusted state machine behind the fixed deployment-control worker. */
final readonly class ThinkPhpUpgradeTaskExecutionService implements UpgradeTaskExecution
{
    private const STEPS = [
        1 => 'preflight',
        2 => 'backup',
        3 => 'restore_verification',
        4 => 'maintenance',
        5 => 'deployment',
        6 => 'smoke',
        7 => 'recovery_pointer',
    ];
    private const FAILURE_CODES = [
        'OPS_UPGRADE_PREFLIGHT_FAILED',
        'OPS_UPGRADE_BACKUP_FAILED',
        'OPS_UPGRADE_RESTORE_FAILED',
        'OPS_UPGRADE_MAINTENANCE_FAILED',
        'OPS_UPGRADE_DEPLOYMENT_FAILED',
        'OPS_UPGRADE_SMOKE_FAILED',
        'OPS_UPGRADE_RECOVERY_POINTER_FAILED',
        'OPS_UPGRADE_WORKER_STALE',
        'OPS_UPGRADE_WORKER_FAILED',
    ];

    public function __construct(
        private AuditContractHost $audit,
        private ThinkPhpOpsTaskDispatcher $tasks,
        private ThinkPhpMaintenanceWindowStore $maintenance,
        private string $projectRoot,
        private BackupRestoreProviderRegistry $backupProviders,
        private ApplicationRuntimeStatusProvider $runtimeStatus,
        private PlatformOperatorIdentityQuery $operators,
    ) {}

    /** @return array<string,mixed>|null */
    public function claim(): ?array
    {
        return $this->transaction(function (): ?array {
            $this->failStaleRunningTasks();
            $task = Db::name('ops_task')->alias('task')
                ->where('task.task_type', PlatformUpgradeExecutionService::TASK_TYPE)
                ->where('task.handler_key', PlatformUpgradeExecutionService::HANDLER_KEY)
                ->where('task.status', 'queued')->where('task.available_at', '<=', Db::raw('UTC_TIMESTAMP(3)'))
                ->field('task.*')->order('task.id')->lock(true)->find();
            if ($task === null) {
                return null;
            }
            $task = $this->withOperatorAccount($task);
            $payload = $this->payload($task);
            $updated = Db::name('ops_task')->where('id', $task['id'])->where('status', 'queued')
                ->where('revision', $task['revision'])->update([
                    'status' => 'running', 'attempt_count' => Db::raw('attempt_count+1'),
                    'revision' => Db::raw('revision+1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_CLAIM_CONFLICT');
            }
            $revision = (int) $task['revision'] + 1;
            $this->createExecution((string) $task['task_key'], $payload);
            $this->startStep((string) $task['task_key'], 'preflight');
            $this->audit($task, 'platform.ops.upgrade.claimed', 'upgrade.claim', [
                'task_key' => $task['task_key'],
                'target_release_key' => $payload['target_release_key'],
                'execution_revision' => $revision,
            ], AuditOutcome::Success, null);
            return [
                'task_key' => (string) $task['task_key'],
                'execution_revision' => $revision,
                'current_step' => 'preflight',
                'target_release_key' => $payload['target_release_key'],
                'target_commit' => $payload['target_commit'],
                'target_tree' => $payload['target_tree'],
                'target_descriptor_sha256' => $payload['target_descriptor_sha256'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function advance(string $taskKey, int $revision): array
    {
        $task = $this->runningTask($taskKey, $revision);
        $execution = $this->execution($taskKey);
        return match ((string) $execution['current_step']) {
            'preflight' => $this->advancePreflight($task, $revision),
            'backup' => $this->advanceBackup($task, $revision),
            'restore_verification' => $this->advanceRestore($task, $revision),
            'maintenance' => $this->advanceMaintenance($task, $revision),
            'deployment' => [
                'action' => 'deploy',
                'target_release_key' => (string) $execution['target_release_key'],
                'target_commit' => (string) $execution['target_commit'],
                'target_tree' => (string) $execution['target_tree'],
                'backup_reference_key' => (string) $execution['backup_reference_key'],
                'backup_manifest_sha256' => $this->backupManifestSha256((string) $execution['backup_reference_key']),
            ],
            default => throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID'),
        };
    }

    /** @return array<string,mixed> */
    public function succeed(string $taskKey, int $revision): array
    {
        $task = $this->runningTask($taskKey, $revision);
        $execution = $this->execution($taskKey);
        if ((string) $execution['current_step'] !== 'deployment') {
            throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID');
        }
        $this->transaction(function () use ($task, $execution): void {
            $this->lockedRunningTask((string) $task['task_key'], (int) $task['revision']);
            $lockedExecution = $this->execution((string) $task['task_key'], true);
            if ((string) $lockedExecution['current_step'] !== 'deployment') {
                throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID');
            }
            $this->succeedStep(
                (string) $task['task_key'],
                'deployment',
                $this->digest([
                    'release_key' => $execution['target_release_key'],
                    'commit' => $execution['target_commit'],
                    'tree' => $execution['target_tree'],
                ]),
            );
            $this->startStep((string) $task['task_key'], 'smoke');
            $moved = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'deployment')->update([
                    'current_step' => 'smoke', 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($moved !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
            }
        });
        $context = $this->context($task);
        $snapshot = $this->runtimeStatus->snapshot($context);
        if ($snapshot->commit === null || $snapshot->tree === null
            || !hash_equals((string) $execution['target_commit'], $snapshot->commit)
            || !hash_equals((string) $execution['target_tree'], $snapshot->tree)
            || $snapshot->releaseKey !== (string) $execution['target_release_key']
            || $snapshot->health === 'unhealthy'
            || $snapshot->pendingMigrations !== 0
            || $snapshot->migrationDrift
            || !$snapshot->repositoryClean
        ) {
            throw new \RuntimeException('OPS_UPGRADE_SMOKE_FAILED');
        }

        $this->transaction(function () use ($task, $snapshot): void {
            $locked = $this->lockedRunningTask((string) $task['task_key'], (int) $task['revision']);
            $lockedExecution = $this->execution((string) $task['task_key'], true);
            if ((string) $lockedExecution['current_step'] !== 'smoke') {
                throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID');
            }
            $smokeOutput = $this->digest([
                'health' => $snapshot->health,
                'migration_digest' => $snapshot->migrationDigest,
                'checks' => $snapshot->checks,
            ]);
            $this->succeedStep((string) $task['task_key'], 'smoke', $smokeOutput);
            $this->startStep((string) $task['task_key'], 'recovery_pointer');

            $moved = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'smoke')->update([
                    'current_step' => 'recovery_pointer', 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($moved !== 1 || (string) $locked['task_key'] !== (string) $task['task_key']) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
            }
        });

        return $this->transaction(function () use ($task, $context): array {
            $locked = $this->lockedRunningTask((string) $task['task_key'], (int) $task['revision']);
            $lockedExecution = $this->execution((string) $task['task_key'], true);
            if ((string) $lockedExecution['current_step'] !== 'recovery_pointer') {
                throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID');
            }

            $backup = Db::name('ops_backup_evidence')->where('backup_reference_key', $lockedExecution['backup_reference_key'])
                ->field('provider_key,backup_reference_key,manifest_sha256')->find();
            $restore = Db::name('ops_restore_evidence')->where('task_key', $lockedExecution['restore_task_key'])
                ->field('target_key,evidence_sha256,verified_at')->find();
            if ($backup === null || $restore === null) {
                throw new \RuntimeException('OPS_UPGRADE_RECOVERY_POINTER_FAILED');
            }
            $pointer = [
                'provider_key' => (string) $backup['provider_key'],
                'backup_reference_key' => (string) $backup['backup_reference_key'],
                'manifest_sha256' => (string) $backup['manifest_sha256'],
                'restore_target_key' => (string) $restore['target_key'],
                'restore_verification_sha256' => (string) $restore['evidence_sha256'],
                'restore_verified_at' => $this->instant((string) $restore['verified_at']),
                'source_commit' => (string) $lockedExecution['source_commit'],
                'target_commit' => (string) $lockedExecution['target_commit'],
                'target_release_key' => (string) $lockedExecution['target_release_key'],
            ];
            $pointerJson = $this->canonicalJson($pointer);
            $pointerSha = hash('sha256', $pointerJson);

            $maintenanceKey = (string) $lockedExecution['maintenance_key'];
            $maintenanceRevision = (int) $lockedExecution['maintenance_revision'];
            $idempotencyDigest = hash('sha256', (string) $task['task_key'] . ':maintenance-close');
            $requestDigest = hash('sha256', $maintenanceKey . ':' . $maintenanceRevision);
            $this->maintenance->close(
                $context,
                $maintenanceKey,
                $maintenanceRevision,
                $idempotencyDigest,
                $requestDigest,
                new OpsAuditEvent('platform.ops.maintenance.closed', 'maintenance.close', [
                    'maintenance_key' => $maintenanceKey,
                    'revision' => $maintenanceRevision,
                    'idempotency_digest' => $idempotencyDigest,
                    'request_digest' => $requestDigest,
                ]),
            );

            $this->succeedStep((string) $task['task_key'], 'recovery_pointer', $pointerSha);
            $executionUpdated = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'recovery_pointer')->update([
                    'current_step' => 'completed', 'recovery_pointer_json' => $pointerJson,
                    'recovery_pointer_sha256' => $pointerSha, 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            $taskUpdated = Db::name('ops_task')->where('id', $locked['id'])->where('status', 'running')
                ->where('revision', $locked['revision'])->update([
                    'status' => 'succeeded', 'revision' => Db::raw('revision+1'), 'last_error_code' => null,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'), 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($executionUpdated !== 1 || $taskUpdated !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_FINALIZE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.upgrade.succeeded', 'upgrade.succeed', [
                'task_key' => $task['task_key'],
                'target_release_key' => $lockedExecution['target_release_key'],
                'recovery_pointer_sha256' => $pointerSha,
            ], AuditOutcome::Success, null);
            return [
                'task_key' => (string) $task['task_key'],
                'status' => 'succeeded',
                'target_release_key' => (string) $lockedExecution['target_release_key'],
                'recovery_pointer_sha256' => $pointerSha,
            ];
        });
    }

    /** @return array{task_key:string,status:string,error_code:string} */
    public function fail(string $taskKey, int $revision, string $errorCode): array
    {
        if (!in_array($errorCode, self::FAILURE_CODES, true)) {
            throw new \InvalidArgumentException('OPS_UPGRADE_FAILURE_CODE_INVALID');
        }
        return $this->transaction(function () use ($taskKey, $revision, $errorCode): array {
            $task = $this->lockedRunningTask($taskKey, $revision);
            $execution = $this->execution($taskKey, true);
            $step = (string) $execution['current_step'];
            if ($step === 'completed' || !in_array($step, self::STEPS, true)) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID');
            }
            $this->failStep($taskKey, $step, $errorCode);
            $updated = Db::name('ops_task')->where('id', $task['id'])->where('status', 'running')
                ->where('revision', $revision)->update([
                    'status' => 'dead', 'revision' => Db::raw('revision+1'), 'last_error_code' => $errorCode,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'), 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_FINALIZE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.upgrade.failed', 'upgrade.fail', [
                'task_key' => $taskKey,
                'failed_step' => $step,
                'error_code' => $errorCode,
            ], AuditOutcome::Error, $errorCode);
            return ['task_key' => $taskKey, 'status' => 'dead', 'error_code' => $errorCode];
        });
    }

    /** @return array{task_key:string,status:string} */
    public function heartbeat(string $taskKey, int $revision): array
    {
        $updated = Db::name('ops_task')->where('task_key', $taskKey)
            ->where('task_type', PlatformUpgradeExecutionService::TASK_TYPE)
            ->where('status', 'running')->where('revision', $revision)
            ->update(['updated_at' => Db::raw('UTC_TIMESTAMP(3)')]);
        if ($updated !== 1) {
            throw new \RuntimeException('OPS_UPGRADE_EXECUTION_FENCED');
        }
        return ['task_key' => $taskKey, 'status' => 'running'];
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function advancePreflight(array $task, int $revision): array
    {
        $payload = $this->payload($task);
        $target = PlatformUpgradeTarget::load($this->projectRoot);
        $context = $this->context($task);
        $readiness = $this->runtimeStatus->upgradeReadiness($context);
        if (($readiness['preflight']['state'] ?? null) !== 'ready'
            || !hash_equals($payload['source_commit'], (string) ($readiness['source']['runtime']['commit'] ?? ''))
            || !hash_equals($payload['source_tree'], (string) ($readiness['source']['runtime']['tree'] ?? ''))
            || !hash_equals($payload['target_commit'], (string) $target->release['commit'])
            || !hash_equals($payload['target_tree'], (string) $target->release['tree'])
            || !hash_equals($payload['target_release_key'], (string) $target->release['key'])
            || !hash_equals($payload['target_descriptor_sha256'], $target->descriptorSha256)
        ) {
            throw new \RuntimeException('OPS_UPGRADE_PREFLIGHT_FAILED');
        }

        return $this->transaction(function () use ($task, $revision, $context, $payload, $readiness): array {
            $this->lockedRunningTask((string) $task['task_key'], $revision);
            $execution = $this->execution((string) $task['task_key'], true);
            if ((string) $execution['current_step'] !== 'preflight') {
                throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID');
            }
            $provider = $this->backupProviders
                ->require(PairedBackupProvider::PROVIDER_KEY);
            $submission = $this->childSubmission(
                Package::BACKUP_TASK_TYPE,
                $provider->backupHandlerKey,
                ['provider_key' => $provider->key],
                Package::BACKUP_TASK_TYPE . '.' . $provider->key,
                $provider->maximumAttempts,
                (string) $task['task_key'] . ':backup',
                'platform.ops.backup.submitted',
                'backup.submit',
            );
            $child = $this->tasks->dispatch($context, $submission);
            $this->succeedStep(
                (string) $task['task_key'],
                'preflight',
                $this->digest(['code' => $readiness['preflight']['code'], 'descriptor' => $payload['target_descriptor_sha256']]),
            );
            $this->startStep((string) $task['task_key'], 'backup');
            $updated = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'preflight')->update([
                    'current_step' => 'backup', 'backup_task_key' => $child->taskKey,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
            }
            return ['action' => 'run_backup', 'child_task_key' => $child->taskKey];
        });
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function advanceBackup(array $task, int $revision): array
    {
        return $this->transaction(function () use ($task, $revision): array {
            $this->lockedRunningTask((string) $task['task_key'], $revision);
            $execution = $this->execution((string) $task['task_key'], true);
            $child = Db::name('ops_task')->where('task_key', $execution['backup_task_key'])->lock(true)->find();
            if ($child === null || (string) $child['status'] !== 'succeeded') {
                if ($child !== null && in_array((string) $child['status'], ['queued', 'running'], true)) {
                    return ['action' => 'wait_backup', 'child_task_key' => (string) $child['task_key']];
                }
                throw new \RuntimeException('OPS_UPGRADE_BACKUP_FAILED');
            }
            $evidence = Db::name('ops_backup_evidence')->where('task_key', $child['task_key'])->lock(true)->find();
            if ($evidence === null
                || !hash_equals((string) $execution['source_commit'], (string) $evidence['source_commit'])
                || !hash_equals((string) $execution['source_tree'], (string) $evidence['source_tree'])
            ) {
                throw new \RuntimeException('OPS_UPGRADE_BACKUP_FAILED');
            }
            $context = $this->context($task);
            $provider = $this->backupProviders
                ->require(PairedBackupProvider::PROVIDER_KEY);
            $payload = [
                'provider_key' => $provider->key,
                'backup_reference_key' => (string) $evidence['backup_reference_key'],
                'target_key' => PairedBackupProvider::RESTORE_TARGET_KEY,
            ];
            $submission = $this->childSubmission(
                Package::RESTORE_TASK_TYPE,
                $provider->restoreHandlerKey,
                $payload,
                Package::RESTORE_TASK_TYPE . '.' . $provider->key,
                $provider->maximumAttempts,
                (string) $task['task_key'] . ':restore',
                'platform.ops.restore.submitted',
                'restore.submit',
            );
            $restore = $this->tasks->dispatch($context, $submission);
            $this->succeedStep((string) $task['task_key'], 'backup', (string) $evidence['manifest_sha256']);
            $this->startStep((string) $task['task_key'], 'restore_verification');
            $updated = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'backup')->update([
                    'current_step' => 'restore_verification', 'backup_reference_key' => $evidence['backup_reference_key'],
                    'restore_task_key' => $restore->taskKey, 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
            }
            return [
                'action' => 'run_restore',
                'child_task_key' => $restore->taskKey,
                'backup_reference_key' => (string) $evidence['backup_reference_key'],
                'backup_manifest_sha256' => (string) $evidence['manifest_sha256'],
            ];
        });
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function advanceRestore(array $task, int $revision): array
    {
        $result = $this->transaction(function () use ($task, $revision): array {
            $this->lockedRunningTask((string) $task['task_key'], $revision);
            $execution = $this->execution((string) $task['task_key'], true);
            $child = Db::name('ops_task')->where('task_key', $execution['restore_task_key'])->lock(true)->find();
            if ($child === null || (string) $child['status'] !== 'succeeded') {
                if ($child !== null && in_array((string) $child['status'], ['queued', 'running'], true)) {
                    return ['action' => 'wait_restore', 'child_task_key' => (string) $child['task_key']];
                }
                throw new \RuntimeException('OPS_UPGRADE_RESTORE_FAILED');
            }
            $evidence = Db::name('ops_restore_evidence')->where('task_key', $child['task_key'])->lock(true)->find();
            if ($evidence === null
                || !hash_equals((string) $execution['backup_reference_key'], (string) $evidence['backup_reference_key'])
                || !hash_equals((string) $execution['source_commit'], (string) $evidence['source_commit'])
                || !hash_equals((string) $execution['source_tree'], (string) $evidence['source_tree'])
            ) {
                throw new \RuntimeException('OPS_UPGRADE_RESTORE_FAILED');
            }
            $this->succeedStep((string) $task['task_key'], 'restore_verification', (string) $evidence['evidence_sha256']);
            $this->startStep((string) $task['task_key'], 'maintenance');
            $updated = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'restore_verification')->update([
                    'current_step' => 'maintenance', 'restore_evidence_sha256' => $evidence['evidence_sha256'],
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
            }
            return ['action' => 'begin_maintenance'];
        });
        if (($result['action'] ?? null) !== 'begin_maintenance') {
            return $result;
        }
        return $this->advanceMaintenance($task, $revision);
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function advanceMaintenance(array $task, int $revision): array
    {
        return $this->transaction(function () use ($task, $revision): array {
            $this->lockedRunningTask((string) $task['task_key'], $revision);
            $execution = $this->execution((string) $task['task_key'], true);
            if ((string) $execution['current_step'] !== 'maintenance'
                || $execution['maintenance_key'] !== null
                || $execution['maintenance_revision'] !== null
            ) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_INVALID');
            }
            $context = $this->context($task);
            $maintenanceKey = 'maintenance_' . bin2hex(random_bytes(16));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $window = new MaintenanceWindow(
                $maintenanceKey,
                'active',
                'planned-upgrade',
                $now->modify('-1 minute')->format('Y-m-d\TH:i:s.v\Z'),
                $now->modify('+23 hours')->format('Y-m-d\TH:i:s.v\Z'),
                1,
            );
            $idempotencyDigest = hash('sha256', (string) $task['task_key'] . ':maintenance-open');
            $requestDigest = hash('sha256', $maintenanceKey . ':' . $window->startsAt . ':' . $window->endsAt);
            $created = $this->maintenance->schedule(
                $context,
                $window,
                0,
                $idempotencyDigest,
                $requestDigest,
                new OpsAuditEvent('platform.ops.maintenance.scheduled', 'maintenance.schedule', [
                    'maintenance_key' => $maintenanceKey,
                    'revision' => 1,
                    'idempotency_digest' => $idempotencyDigest,
                    'request_digest' => $requestDigest,
                ]),
            );
            $updated = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'maintenance')->update([
                    'maintenance_key' => $created->maintenanceKey, 'maintenance_revision' => $created->revision,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
            }

            $readiness = $this->runtimeStatus->upgradeReadiness($context);
            if (($readiness['state'] ?? null) !== 'ready'
                || !hash_equals((string) $execution['target_descriptor_sha256'], (string) ($readiness['target']['descriptor_sha256'] ?? ''))
                || !hash_equals((string) $execution['backup_reference_key'], (string) ($readiness['recovery_pointer']['backup_reference_key'] ?? ''))
            ) {
                throw new \RuntimeException('OPS_UPGRADE_MAINTENANCE_FAILED');
            }
            $this->succeedStep(
                (string) $task['task_key'],
                'maintenance',
                $this->digest(['maintenance_key' => $created->maintenanceKey, 'revision' => $created->revision]),
            );
            $this->startStep((string) $task['task_key'], 'deployment');
            $moved = Db::name('ops_upgrade_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'maintenance')->update([
                    'current_step' => 'deployment', 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($moved !== 1) {
                throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
            }
            return [
                'action' => 'deploy',
                'target_release_key' => (string) $execution['target_release_key'],
                'target_commit' => (string) $execution['target_commit'],
                'target_tree' => (string) $execution['target_tree'],
                'backup_reference_key' => (string) $execution['backup_reference_key'],
                'backup_manifest_sha256' => $this->backupManifestSha256((string) $execution['backup_reference_key']),
            ];
        });
    }

    private function backupManifestSha256(string $backupReferenceKey): string
    {
        $digest = Db::name('ops_backup_evidence')->where('backup_reference_key', $backupReferenceKey)
            ->value('manifest_sha256');
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException('OPS_UPGRADE_BACKUP_FAILED');
        }
        return $digest;
    }

    /** @param array<string,mixed> $payload */
    private function createExecution(string $taskKey, array $payload): void
    {
        Db::name('ops_upgrade_execution')->insertOrIgnore([
            ...$payload,
            'task_key' => $taskKey,
            'source_release_key' => $payload['source_release_key'] === '' ? null : $payload['source_release_key'],
            'current_step' => 'preflight',
        ]);
        $inputBase = ['task_key' => $taskKey, 'source' => $payload['source_commit'], 'target' => $payload['target_commit'], 'descriptor' => $payload['target_descriptor_sha256']];
        foreach (self::STEPS as $order => $step) {
            Db::name('ops_upgrade_step')->insertOrIgnore([
                'task_key' => $taskKey,
                'step_key' => $step,
                'step_order' => $order,
                'status' => 'pending',
                'input_sha256' => $this->digest([...$inputBase, 'step' => $step]),
            ]);
        }
    }

    private function startStep(string $taskKey, string $step): void
    {
        $updated = Db::name('ops_upgrade_step')->where('task_key', $taskKey)->where('step_key', $step)
            ->where('status', 'pending')->update(['status' => 'running', 'started_at' => Db::raw('UTC_TIMESTAMP(3)')]);
        if ($updated !== 1) {
            throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
        }
    }

    private function succeedStep(string $taskKey, string $step, string $outputSha256): void
    {
        $updated = Db::name('ops_upgrade_step')->where('task_key', $taskKey)->where('step_key', $step)
            ->where('status', 'running')->update([
                'status' => 'succeeded', 'output_sha256' => $outputSha256, 'last_error_code' => null,
                'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
        if ($updated !== 1) {
            throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
        }
    }

    private function failStep(string $taskKey, string $step, string $errorCode): void
    {
        $updated = Db::name('ops_upgrade_step')->where('task_key', $taskKey)->where('step_key', $step)
            ->where('status', 'running')->update([
                'status' => 'failed', 'last_error_code' => $errorCode,
                'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
        if ($updated !== 1) {
            throw new \RuntimeException('OPS_UPGRADE_STEP_CONFLICT');
        }
    }

    private function failStaleRunningTasks(): void
    {
        $rows = Db::name('ops_task')->alias('task')->join('ops_upgrade_execution execution', 'execution.task_key=task.task_key')
            ->where('task.task_type', PlatformUpgradeExecutionService::TASK_TYPE)->where('task.status', 'running')
            ->where('task.updated_at', '<', Db::raw('UTC_TIMESTAMP(3)-INTERVAL 2 HOUR'))
            ->field('task.task_key,execution.current_step')->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            $this->failStep((string) $row['task_key'], (string) $row['current_step'], 'OPS_UPGRADE_WORKER_STALE');
            Db::name('ops_task')->where('task_key', $row['task_key'])->where('status', 'running')->update([
                'status' => 'dead', 'revision' => Db::raw('revision+1'), 'last_error_code' => 'OPS_UPGRADE_WORKER_STALE',
                'completed_at' => Db::raw('UTC_TIMESTAMP(3)'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
        }
    }

    private function childSubmission(
        string $taskType,
        string $handlerKey,
        array $payload,
        string $concurrencyKey,
        int $maximumAttempts,
        string $idempotencyKey,
        string $eventType,
        string $action,
    ): OpsTaskSubmission {
        $idempotencyDigest = hash('sha256', $idempotencyKey);
        $requestDigest = hash('sha256', $this->canonicalJson([
            'task_type' => $taskType,
            'payload' => $payload,
        ]));
        return new OpsTaskSubmission(
            $taskType,
            $handlerKey,
            $payload,
            $idempotencyDigest,
            $requestDigest,
            $concurrencyKey,
            $maximumAttempts,
            new OpsAuditEvent($eventType, $action, array_filter([
                'provider_key' => $payload['provider_key'],
                'target_key' => $payload['target_key'] ?? null,
                'idempotency_digest' => $idempotencyDigest,
                'request_digest' => $requestDigest,
            ], static fn(mixed $value): bool => $value !== null)),
        );
    }

    /** @param array<string,mixed> $task @return array<string,string> */
    private function payload(array $task): array
    {
        try {
            $payload = json_decode((string) $task['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('OPS_UPGRADE_PAYLOAD_INVALID');
        }
        $expected = [
            'source_application_manifest_sha256', 'source_commit', 'source_release_key',
            'source_tree', 'target_commit', 'target_descriptor_sha256',
            'target_release_key', 'target_tree',
        ];
        $keys = is_array($payload) ? array_keys($payload) : [];
        sort($keys, SORT_STRING);
        if ($keys !== $expected) {
            throw new \RuntimeException('OPS_UPGRADE_PAYLOAD_INVALID');
        }
        foreach ($payload as $value) {
            if (!is_string($value)) {
                throw new \RuntimeException('OPS_UPGRADE_PAYLOAD_INVALID');
            }
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $payload['source_commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $payload['source_tree']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $payload['target_commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $payload['target_tree']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payload['source_application_manifest_sha256']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payload['target_descriptor_sha256']) !== 1
            || preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $payload['target_release_key']) !== 1
            || ($payload['source_release_key'] !== ''
                && preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $payload['source_release_key']) !== 1)
        ) {
            throw new \RuntimeException('OPS_UPGRADE_PAYLOAD_INVALID');
        }
        return $payload;
    }

    /** @param array<string,mixed> $task */
    private function context(array $task): PlatformContext
    {
        return PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(
                1,
                'upgrade-worker-' . substr((string) $task['task_key'], 4, 16),
                (int) $task['account_id'],
                (int) $task['submitted_by_operator_id'],
                'platform-web',
                new DateTimeImmutable('now'),
            ),
            'upgrade-' . substr((string) $task['task_key'], 4),
        );
    }

    /** @return array<string,mixed> */
    private function runningTask(string $taskKey, int $revision): array
    {
        $task = $this->taskQuery($taskKey, $revision)->find();
        if ($task === null) {
            throw new \RuntimeException('OPS_UPGRADE_EXECUTION_FENCED');
        }
        return $this->withOperatorAccount($task);
    }

    /** @return array<string,mixed> */
    private function lockedRunningTask(string $taskKey, int $revision): array
    {
        $task = $this->taskQuery($taskKey, $revision)->lock(true)->find();
        if ($task === null) {
            throw new \RuntimeException('OPS_UPGRADE_EXECUTION_FENCED');
        }
        return $this->withOperatorAccount($task);
    }

    /** @return array<string,mixed> */
    private function execution(string $taskKey, bool $forUpdate = false): array
    {
        $query = Db::name('ops_upgrade_execution')->where('task_key', $taskKey);
        $row = ($forUpdate ? $query->lock(true) : $query)->find();
        if ($row === null) {
            throw new \RuntimeException('OPS_UPGRADE_EXECUTION_UNAVAILABLE');
        }
        return $row;
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $metadata */
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
            'upgrade-' . substr((string) $task['task_key'], 4),
            (int) $task['submitted_by_operator_id'],
            $this->operators->accountId((int) $task['submitted_by_operator_id']),
            $metadata,
            $outcome,
            $reasonCode,
        );
    }

    private function taskQuery(string $taskKey, int $revision): \think\db\Query
    {
        return Db::name('ops_task')->alias('task')
            ->where('task.task_key', $taskKey)->where('task.task_type', PlatformUpgradeExecutionService::TASK_TYPE)
            ->where('task.status', 'running')->where('task.revision', $revision)->field('task.*');
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function withOperatorAccount(array $task): array
    {
        $task['account_id'] = $this->operators->accountId((int) $task['submitted_by_operator_id']);

        return $task;
    }

    private function transaction(callable $operation): mixed
    {
        return Db::transaction($operation);
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        ksort($value, SORT_STRING);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
