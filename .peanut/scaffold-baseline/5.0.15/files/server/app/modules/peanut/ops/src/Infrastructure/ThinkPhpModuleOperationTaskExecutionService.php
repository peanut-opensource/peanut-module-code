<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Ops\Contract\ModuleTaskExecution;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\exception\plugin\PluginLifecycleException;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Modules\Ops\Service\DeploymentModuleRequestService;
use PeanutAdmin\Modules\Ops\Service\PlatformModuleOperationExecutionService;
use app\common\services\audit\AuditContractHost;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceWindow;
use PeanutAdmin\Modules\Ops\Domain\Package;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsAuditEvent;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskSubmission;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProviderRegistry;
use Closure;
use think\facade\Db;

/** Trusted state machine for one registry-bound Module delivery request. */
final readonly class ThinkPhpModuleOperationTaskExecutionService implements ModuleTaskExecution
{
    private const FAILURE_CODES = [
        'OPS_MODULE_PREFLIGHT_FAILED',
        'OPS_MODULE_BACKUP_FAILED',
        'OPS_MODULE_RESTORE_FAILED',
        'OPS_MODULE_MAINTENANCE_FAILED',
        'OPS_MODULE_EXECUTION_FAILED',
        'OPS_MODULE_SMOKE_FAILED',
        'OPS_MODULE_RECOVERY_POINTER_FAILED',
        'OPS_MODULE_WORKER_STALE',
        'OPS_MODULE_WORKER_FAILED',
    ];

    public function __construct(
        private AuditContractHost $audit,
        private ThinkPhpOpsTaskDispatcher $tasks,
        private ThinkPhpMaintenanceWindowStore $maintenance,
        private DeploymentModuleRequestService $requests,
        private BackupRestoreProviderRegistry $backupProviders,
        private ApplicationRuntimeStatusProvider|Closure $runtimeStatus,
        private PlatformOperatorIdentityQuery $operators,
        private PlatformPermissionChecker $permissions,
    ) {}

    /** The native CLI owns one queued development job; no detached process or second task ledger. */
    public function work(): ?array
    {
        $claim = $this->claimTask(true);
        if ($claim === null) {
            return null;
        }
        $key = $claim['task_key'];
        $revision = $claim['execution_revision'];
        $failure = 'OPS_MODULE_PREFLIGHT_FAILED';
        try {
            $next = $this->advance($key, $revision);
            if (($next['action'] ?? null) !== 'execute') {
                throw new \RuntimeException('OPS_MODULE_STEP_INVALID');
            }
            $failure = 'OPS_MODULE_EXECUTION_FAILED';
            $this->execute($key, $revision);
            $failure = 'OPS_MODULE_SMOKE_FAILED';
            return $this->succeed($key, $revision);
        } catch (\Throwable $exception) {
            $code = match (true) {
                $exception instanceof PluginPackageException, $exception instanceof PluginLifecycleException => $exception->errorCode,
                $exception instanceof OpsConsoleException => $exception->problemCode,
                preg_match('/^OPS_MODULE_[A-Z0-9_]+$/D', $exception->getMessage()) === 1 => $exception->getMessage(),
                default => $failure,
            };
            $reason = $exception instanceof PluginPackageException || $exception instanceof PluginLifecycleException
                || $exception instanceof OpsConsoleException || $code !== $failure
                ? $exception->getMessage() : 'Module operation failed; preserve recovery state and inspect the restricted operator log.';
            $result = ['error_code' => $code, 'reason' => $reason,
                'exit_code' => $exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1];
            if ($exception instanceof PluginPackageException) {
                $result['details'] = $exception->details;
            }
            $cause = $exception;
            while ($cause->getPrevious() instanceof PluginPackageException
                || $cause->getPrevious() instanceof PluginLifecycleException) {
                $cause = $cause->getPrevious();
            }
            if ($cause !== $exception) {
                $result['cause'] = ['error_code' => $cause->errorCode, 'reason' => $cause->getMessage(),
                    'details' => $cause instanceof PluginPackageException ? $cause->details : []];
                if ($cause->getCode() > 0 && $cause->getCode() < 256) {
                    $result['exit_code'] = $cause->getCode();
                }
            }
            $this->transaction(function () use ($key, $revision, $failure, $result): void {
                $this->lockedRunningTask($key, $revision);
                $current = $this->execution($key, true);
                if (is_string($current['operation_result_json'])) {
                    $result['operation_result'] = json_decode($current['operation_result_json'], true, 128, JSON_THROW_ON_ERROR);
                }
                $json = $this->canonicalJson($result);
                Db::name('ops_module_execution')->where('task_key', $key)->update([
                    'operation_result_json' => $json, 'operation_result_sha256' => hash('sha256', $json),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
                $this->fail($key, $revision, $failure);
            });
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function claim(): ?array
    {
        return $this->claimTask(false);
    }

    /** @return array<string,mixed>|null */
    private function claimTask(bool $local): ?array
    {
        if ($local) {
            $this->requests->assertLocalWorker();
        }
        return $this->transaction(function () use ($local): ?array {
            $this->failStaleRunningTasks();
            $query = Db::name('ops_task')->alias('task')
                ->join('ops_module_request request', "request.request_key=JSON_UNQUOTE(JSON_EXTRACT(task.payload_json, '$.request_key'))")
                ->where('task.task_type', PlatformModuleOperationExecutionService::TASK_TYPE)
                ->where('task.handler_key', PlatformModuleOperationExecutionService::HANDLER_KEY)
                ->where('task.status', 'queued')->where('task.available_at', '<=', Db::raw('UTC_TIMESTAMP(3)'))
                ->field('task.*')->order('task.id')->lock(true);
            if ($local) {
                $query->where('request.environment', 'development')->where('request.delivery_resource_id', '')
                    ->where('request.target_resource_id', '')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(task.payload_json, '$.application_root')) = ?", [$this->requests->applicationRoot()]);
            } else {
                $query->where('request.environment', '<>', 'development');
            }
            $task = $query->find();
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
                throw new \RuntimeException('OPS_MODULE_CLAIM_CONFLICT');
            }
            $revision = (int) $task['revision'] + 1;
            Db::name('ops_module_execution')->insert([
                'task_key' => $task['task_key'], 'request_key' => $payload['request_key'], 'current_step' => 'preflight',
            ]);
            $this->audit($task, 'platform.ops.module.claimed', 'module.claim', [
                'task_key' => $task['task_key'],
                'request_key' => $payload['request_key'],
                'environment' => $payload['environment'],
                'target_resource_id' => $payload['target_resource_id'],
                'package_key' => $payload['package_key'],
                'operation' => $payload['operation'],
                'execution_revision' => $revision,
            ], AuditOutcome::Success, null);
            return [
                'task_key' => (string) $task['task_key'],
                'execution_revision' => $revision,
                'current_step' => 'preflight',
                'operation' => $payload['operation'],
                'package_key' => $payload['package_key'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function advance(string $taskKey, int $revision): array
    {
        $task = $this->runningTask($taskKey, $revision);
        return match ((string) $this->execution($taskKey)['current_step']) {
            'preflight' => $this->advancePreflight($task, $revision),
            'backup' => $this->advanceBackup($task, $revision),
            'restore_verification' => $this->advanceRestore($task, $revision),
            'maintenance' => $this->advanceMaintenance($task, $revision),
            'execution' => ['action' => 'execute'],
            'smoke' => ['action' => 'finalize'],
            default => throw new \RuntimeException('OPS_MODULE_STEP_INVALID'),
        };
    }

    /** @return array<string,mixed> */
    public function execute(string $taskKey, int $revision): array
    {
        $task = $this->runningTask($taskKey, $revision);
        $execution = $this->execution($taskKey);
        if ((string) $execution['current_step'] !== 'execution') {
            throw new \RuntimeException('OPS_MODULE_STEP_INVALID');
        }
        $payload = $this->payload($task);
        $request = $this->requestStore()->assertPrepared($payload['request_key']);
        $permission = PlatformModuleOperationExecutionService::modulePermission($payload['operation']);
        if ($this->requests->isLocal($request)
            && ($permission === null || !$this->permissions->allows($this->context($task), $permission))) {
            throw OpsConsoleException::denied();
        }
        $result = $this->requestStore()->execute($payload['request_key']);
        $expected = match ($payload['operation']) {
            'install' => ['installed', 'reactivated', 'resumed', 'recovered', 'unchanged'],
            'update' => ['upgraded', 'unchanged'],
            'retire' => ['retired', 'unchanged'],
            'purge' => ['purged', 'unchanged'],
            default => [],
        };
        if (!in_array((string) ($result['operation'] ?? ''), $expected, true)
            || !hash_equals($payload['package_key'], (string) ($result['package_key'] ?? ''))
        ) {
            throw new \RuntimeException('OPS_MODULE_EXECUTION_FAILED');
        }
        $json = $this->canonicalJson($result);
        $sha = hash('sha256', $json);
        $this->transaction(function () use ($taskKey, $revision, $json, $sha): void {
            $this->lockedRunningTask($taskKey, $revision);
            $updated = Db::name('ops_module_execution')->where('task_key', $taskKey)->where('current_step', 'execution')
                ->update(['current_step' => 'smoke', 'operation_result_json' => $json,
                    'operation_result_sha256' => $sha, 'updated_at' => Db::raw('UTC_TIMESTAMP(3)')]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_MODULE_STEP_CONFLICT');
            }
        });
        return ['action' => 'run_smoke', 'result_sha256' => $sha];
    }

    /** @return array<string,mixed> */
    public function succeed(string $taskKey, int $revision): array
    {
        $task = $this->runningTask($taskKey, $revision);
        $payload = $this->payload($task);
        $execution = $this->execution($taskKey);
        if ((string) $execution['current_step'] !== 'smoke') {
            throw new \RuntimeException('OPS_MODULE_STEP_INVALID');
        }
        $result = json_decode((string) $execution['operation_result_json'], true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !$this->smoke($payload, $result)) {
            throw new \RuntimeException('OPS_MODULE_SMOKE_FAILED');
        }
        return $this->transaction(function () use ($task, $payload): array {
            $locked = $this->lockedRunningTask((string) $task['task_key'], (int) $task['revision']);
            $current = $this->execution((string) $task['task_key'], true);
            if ((string) $current['current_step'] !== 'smoke') {
                throw new \RuntimeException('OPS_MODULE_STEP_INVALID');
            }
            $pointer = $this->recoveryPointer($payload, $current);
            $pointerJson = $this->canonicalJson($pointer);
            $pointerSha = hash('sha256', $pointerJson);
            $context = $this->context($task);
            $maintenanceKey = (string) $current['maintenance_key'];
            $maintenanceRevision = (int) $current['maintenance_revision'];
            $this->maintenance->close(
                $context,
                $maintenanceKey,
                $maintenanceRevision,
                hash('sha256', (string) $task['task_key'] . ':maintenance-close'),
                hash('sha256', $maintenanceKey . ':' . $maintenanceRevision),
                new OpsAuditEvent('platform.ops.maintenance.closed', 'maintenance.close', [
                    'maintenance_key' => $maintenanceKey,
                    'revision' => $maintenanceRevision,
                ]),
            );
            $executionUpdated = Db::name('ops_module_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'smoke')->update([
                    'current_step' => 'completed', 'recovery_pointer_json' => $pointerJson,
                    'recovery_pointer_sha256' => $pointerSha, 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            $taskUpdated = Db::name('ops_task')->where('id', $locked['id'])->where('status', 'running')
                ->where('revision', $locked['revision'])->update([
                    'status' => 'succeeded', 'revision' => Db::raw('revision+1'), 'last_error_code' => null,
                    'completed_at' => Db::raw('UTC_TIMESTAMP(3)'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($executionUpdated !== 1 || $taskUpdated !== 1) {
                throw new \RuntimeException('OPS_MODULE_FINALIZE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.module.succeeded', 'module.succeed', [
                'task_key' => $task['task_key'],
                'request_key' => $payload['request_key'],
                'environment' => $payload['environment'],
                'target_resource_id' => $payload['target_resource_id'],
                'package_key' => $payload['package_key'],
                'operation' => $payload['operation'],
                'recovery_pointer_sha256' => $pointerSha,
            ], AuditOutcome::Success, null);
            return [
                'task_key' => (string) $task['task_key'],
                'status' => 'succeeded',
                'operation' => $payload['operation'],
                'package_key' => $payload['package_key'],
                'recovery_pointer_sha256' => $pointerSha,
            ];
        });
    }

    /** @return array{task_key:string,status:string,error_code:string,recovery_pointer_sha256:?string} */
    public function fail(string $taskKey, int $revision, string $errorCode): array
    {
        if (!in_array($errorCode, self::FAILURE_CODES, true)) {
            throw new \InvalidArgumentException('OPS_MODULE_FAILURE_CODE_INVALID');
        }
        return $this->transaction(function () use ($taskKey, $revision, $errorCode): array {
            $task = $this->lockedRunningTask($taskKey, $revision);
            $payload = $this->payload($task);
            $execution = $this->execution($taskKey, true);
            $pointerSha = null;
            if ($execution['backup_reference_key'] !== null || $this->requests->isLocal($payload)) {
                $pointer = $this->recoveryPointer($payload, $execution) + [
                    'failed_step' => (string) $execution['current_step'],
                    'error_code' => $errorCode,
                ];
                $pointerJson = $this->canonicalJson($pointer);
                $pointerSha = hash('sha256', $pointerJson);
                Db::name('ops_module_execution')->where('task_key', $taskKey)->update([
                    'recovery_pointer_json' => $pointerJson, 'recovery_pointer_sha256' => $pointerSha,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            }
            $updated = Db::name('ops_task')->where('id', $task['id'])->where('status', 'running')
                ->where('revision', $revision)->update([
                    'status' => 'dead', 'revision' => Db::raw('revision+1'), 'last_error_code' => $errorCode,
                    'completed_at' => Db::raw('UTC_TIMESTAMP(3)'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_MODULE_FINALIZE_CONFLICT');
            }
            $this->audit($task, 'platform.ops.module.failed', 'module.fail', [
                'task_key' => $taskKey,
                'request_key' => $payload['request_key'],
                'environment' => $payload['environment'],
                'target_resource_id' => $payload['target_resource_id'],
                'package_key' => $payload['package_key'],
                'operation' => $payload['operation'],
                'failed_step' => $execution['current_step'],
                'error_code' => $errorCode,
                'recovery_pointer_sha256' => $pointerSha,
            ], AuditOutcome::Error, $errorCode);
            return [
                'task_key' => $taskKey,
                'status' => 'dead',
                'error_code' => $errorCode,
                'recovery_pointer_sha256' => $pointerSha,
            ];
        });
    }

    /** @return array{task_key:string,status:string} */
    public function heartbeat(string $taskKey, int $revision): array
    {
        $updated = Db::name('ops_task')->where('task_key', $taskKey)
            ->where('task_type', PlatformModuleOperationExecutionService::TASK_TYPE)
            ->where('status', 'running')->where('revision', $revision)
            ->update(['updated_at' => Db::raw('UTC_TIMESTAMP(3)')]);
        if ($updated !== 1) {
            throw new \RuntimeException('OPS_MODULE_EXECUTION_FENCED');
        }
        return ['task_key' => $taskKey, 'status' => 'running'];
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function advancePreflight(array $task, int $revision): array
    {
        $payload = $this->payload($task);
        $request = $this->requestStore()->assertPrepared($payload['request_key']);
        $runtime = $this->runtime($this->context($task));
        if (!hash_equals($payload['request_sha256'], (string) $request['request_sha256'])
            || !hash_equals($payload['source_commit'], $runtime['commit'])
            || !hash_equals($payload['source_tree'], $runtime['tree'])
            || $runtime['health'] === 'unhealthy'
        ) {
            throw new \RuntimeException('OPS_MODULE_PREFLIGHT_FAILED');
        }
        $this->requestStore()->preview(
            $payload['delivery_resource_id'],
            $payload['target_resource_id'],
            $payload['operation'],
            $payload['package_key'],
            $payload['archive_sha256'] === '' ? null : $payload['archive_sha256'],
            $payload['signature_key_id'] === '' ? null : $payload['signature_key_id'],
        );
        if ($this->requests->isLocal($request)) {
            $permission = PlatformModuleOperationExecutionService::modulePermission($payload['operation']);
            if ($permission === null || !$this->permissions->allows($this->context($task), $permission)) {
                throw OpsConsoleException::denied();
            }
            $this->transaction(function () use ($task, $revision): void {
                $this->lockedRunningTask((string) $task['task_key'], $revision);
                $updated = Db::name('ops_module_execution')->where('task_key', $task['task_key'])
                    ->where('current_step', 'preflight')->update([
                        'current_step' => 'maintenance', 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                    ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('OPS_MODULE_STEP_CONFLICT');
                }
            });
            return $this->advanceMaintenance($task, $revision);
        }
        return $this->transaction(function () use ($task, $revision): array {
            $this->lockedRunningTask((string) $task['task_key'], $revision);
            $execution = $this->execution((string) $task['task_key'], true);
            if ((string) $execution['current_step'] !== 'preflight') {
                throw new \RuntimeException('OPS_MODULE_STEP_INVALID');
            }
            $context = $this->context($task);
            $provider = $this->backupProviders->require(PairedBackupProvider::PROVIDER_KEY);
            $child = $this->tasks->dispatch($context, $this->childSubmission(
                Package::BACKUP_TASK_TYPE,
                $provider->backupHandlerKey,
                ['provider_key' => $provider->key],
                Package::BACKUP_TASK_TYPE . '.' . $provider->key,
                $provider->maximumAttempts,
                (string) $task['task_key'] . ':backup',
                'platform.ops.backup.submitted',
                'backup.submit',
            ));
            $updated = Db::name('ops_module_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'preflight')->update([
                    'current_step' => 'backup', 'backup_task_key' => $child->taskKey,
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_MODULE_STEP_CONFLICT');
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
                throw new \RuntimeException('OPS_MODULE_BACKUP_FAILED');
            }
            $evidence = Db::name('ops_backup_evidence')->where('task_key', $child['task_key'])->lock(true)->find();
            $payload = $this->payload($task);
            if ($evidence === null
                || !hash_equals($payload['source_commit'], (string) $evidence['source_commit'])
                || !hash_equals($payload['source_tree'], (string) $evidence['source_tree'])
            ) {
                throw new \RuntimeException('OPS_MODULE_BACKUP_FAILED');
            }
            $provider = $this->backupProviders->require(PairedBackupProvider::PROVIDER_KEY);
            $restore = $this->tasks->dispatch($this->context($task), $this->childSubmission(
                Package::RESTORE_TASK_TYPE,
                $provider->restoreHandlerKey,
                [
                    'provider_key' => $provider->key,
                    'backup_reference_key' => (string) $evidence['backup_reference_key'],
                    'target_key' => PairedBackupProvider::RESTORE_TARGET_KEY,
                ],
                Package::RESTORE_TASK_TYPE . '.' . $provider->key,
                $provider->maximumAttempts,
                (string) $task['task_key'] . ':restore',
                'platform.ops.restore.submitted',
                'restore.submit',
            ));
            $updated = Db::name('ops_module_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'backup')->update([
                    'current_step' => 'restore_verification', 'backup_reference_key' => $evidence['backup_reference_key'],
                    'restore_task_key' => $restore->taskKey, 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_MODULE_STEP_CONFLICT');
            }
            return ['action' => 'run_restore', 'child_task_key' => $restore->taskKey];
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
                throw new \RuntimeException('OPS_MODULE_RESTORE_FAILED');
            }
            $evidence = Db::name('ops_restore_evidence')->where('task_key', $child['task_key'])->lock(true)->find();
            $payload = $this->payload($task);
            if ($evidence === null
                || !hash_equals((string) $execution['backup_reference_key'], (string) $evidence['backup_reference_key'])
                || !hash_equals($payload['source_commit'], (string) $evidence['source_commit'])
                || !hash_equals($payload['source_tree'], (string) $evidence['source_tree'])
            ) {
                throw new \RuntimeException('OPS_MODULE_RESTORE_FAILED');
            }
            $updated = Db::name('ops_module_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'restore_verification')->update([
                    'current_step' => 'maintenance', 'restore_evidence_sha256' => $evidence['evidence_sha256'],
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_MODULE_STEP_CONFLICT');
            }
            return ['action' => 'begin_maintenance'];
        });
        return ($result['action'] ?? null) === 'begin_maintenance'
            ? $this->advanceMaintenance($task, $revision) : $result;
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function advanceMaintenance(array $task, int $revision): array
    {
        return $this->transaction(function () use ($task, $revision): array {
            $this->lockedRunningTask((string) $task['task_key'], $revision);
            $execution = $this->execution((string) $task['task_key'], true);
            if ((string) $execution['current_step'] !== 'maintenance'
                || $execution['maintenance_key'] !== null
            ) {
                throw new \RuntimeException('OPS_MODULE_STEP_INVALID');
            }
            $context = $this->context($task);
            $key = 'maintenance_' . bin2hex(random_bytes(16));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $window = new MaintenanceWindow(
                $key,
                'active',
                'module-lifecycle',
                $now->modify('-1 minute')->format('Y-m-d\TH:i:s.v\Z'),
                $now->modify('+23 hours')->format('Y-m-d\TH:i:s.v\Z'),
                1,
            );
            $idempotencyDigest = hash('sha256', (string) $task['task_key'] . ':maintenance-open');
            $requestDigest = hash('sha256', $key . ':' . $window->startsAt . ':' . $window->endsAt);
            $created = $this->maintenance->schedule(
                $context,
                $window,
                0,
                $idempotencyDigest,
                $requestDigest,
                new OpsAuditEvent('platform.ops.maintenance.scheduled', 'maintenance.schedule', [
                    'maintenance_key' => $key,
                    'revision' => 1,
                    'idempotency_digest' => $idempotencyDigest,
                    'request_digest' => $requestDigest,
                ]),
            );
            $updated = Db::name('ops_module_execution')->where('task_key', $task['task_key'])
                ->where('current_step', 'maintenance')->update([
                    'current_step' => 'execution', 'maintenance_key' => $created->maintenanceKey,
                    'maintenance_revision' => $created->revision, 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('OPS_MODULE_STEP_CONFLICT');
            }
            return ['action' => 'execute'];
        });
    }

    /** @param array<string,string> $payload @param array<string,mixed> $result */
    private function smoke(array $payload, array $result): bool
    {
        if (!hash_equals($payload['package_key'], (string) ($result['package_key'] ?? ''))) {
            return false;
        }
        $row = Db::name('plugin_installation')->where('plugin_key', $payload['package_key'])
            ->field('status,installed_version,artifact_sha256,lock_digest')->find();
        if (in_array($payload['operation'], ['install', 'update'], true)) {
            return is_array($row)
                && (string) $row['status'] === 'active'
                && hash_equals((string) ($result['version'] ?? ''), (string) $row['installed_version'])
                && hash_equals((string) ($result['artifact_sha256'] ?? ''), (string) $row['artifact_sha256']);
        }
        if ($payload['operation'] === 'retire') {
            return is_array($row) && (string) $row['status'] === 'uninstalled';
        }
        if ($row !== null) {
            return false;
        }
        $modules = $result['affected_modules'] ?? [];
        if (!is_array($modules) || $modules === []) {
            return ($result['operation'] ?? null) === 'unchanged';
        }
        $keys = array_values(array_filter(array_column($modules, 'module_key'), 'is_string'));
        if (count($keys) !== count($modules)) {
            return false;
        }
        return count((new TenantModuleStateQueries())->installationStates($keys)) === 0;
    }

    /** @param array<string,string> $payload @param array<string,mixed> $execution @return array<string,mixed> */
    private function recoveryPointer(array $payload, array $execution): array
    {
        if ($this->requests->isLocal($payload)) {
            return ['schema_version' => 1, 'environment' => 'development',
                'request_key' => $payload['request_key'], 'request_sha256' => $payload['request_sha256'],
                'target_resource_id' => $payload['target_resource_id'], 'package_key' => $payload['package_key'],
                'operation' => $payload['operation'], 'source_commit' => $payload['source_commit'],
                'source_tree' => $payload['source_tree'], 'recovery_owner' => 'application Module installer journal',
                'paired_backup_verified' => false, 'operation_result_sha256' => $execution['operation_result_sha256']];
        }
        if (!is_string($execution['backup_reference_key'] ?? null)
            || !is_string($execution['restore_evidence_sha256'] ?? null)
        ) {
            throw new \RuntimeException('OPS_MODULE_RECOVERY_POINTER_FAILED');
        }
        $backup = Db::name('ops_backup_evidence')->where('backup_reference_key', $execution['backup_reference_key'])
            ->field('provider_key,manifest_sha256')->find();
        if ($backup === null) {
            throw new \RuntimeException('OPS_MODULE_RECOVERY_POINTER_FAILED');
        }
        return [
            'schema_version' => 1,
            'request_key' => $payload['request_key'],
            'request_sha256' => $payload['request_sha256'],
            'environment' => $payload['environment'],
            'target_resource_id' => $payload['target_resource_id'],
            'package_key' => $payload['package_key'],
            'operation' => $payload['operation'],
            'source_commit' => $payload['source_commit'],
            'source_tree' => $payload['source_tree'],
            'provider_key' => (string) $backup['provider_key'],
            'backup_reference_key' => (string) $execution['backup_reference_key'],
            'backup_manifest_sha256' => (string) $backup['manifest_sha256'],
            'restore_evidence_sha256' => (string) $execution['restore_evidence_sha256'],
            'operation_result_sha256' => $execution['operation_result_sha256'] === null
                ? null : (string) $execution['operation_result_sha256'],
        ];
    }

    private function requestStore(): DeploymentModuleRequestService
    {
        return $this->requests;
    }

    /** @return array{commit:string,tree:string,health:string,repository_clean:bool} */
    private function runtime(PlatformContext $context): array
    {
        if ($this->runtimeStatus instanceof Closure) {
            $identity = ($this->runtimeStatus)($context);
            if (is_array($identity)) {
                return $identity;
            }
        }
        $snapshot = $this->runtimeStatus->snapshot($context);
        if ($snapshot->commit === null || $snapshot->tree === null || $snapshot->repositoryClean === null) {
            throw OpsConsoleException::sourceIdentityUnknown();
        }
        return [
            'commit' => $snapshot->commit,
            'tree' => $snapshot->tree,
            'health' => $snapshot->health,
            'repository_clean' => $snapshot->repositoryClean,
        ];
    }


    private function failStaleRunningTasks(): void
    {
        $rows = Db::name('ops_task')->alias('task')->join('ops_module_execution execution', 'execution.task_key=task.task_key')
            ->where('task.task_type', PlatformModuleOperationExecutionService::TASK_TYPE)->where('task.status', 'running')
            ->where('task.updated_at', '<', Db::raw('UTC_TIMESTAMP(3)-INTERVAL 2 HOUR'))
            ->field('task.task_key')->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            Db::name('ops_task')->where('task_key', $row['task_key'])->where('status', 'running')->update([
                'status' => 'dead', 'revision' => Db::raw('revision+1'), 'last_error_code' => 'OPS_MODULE_WORKER_STALE',
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
        $requestDigest = hash('sha256', $this->canonicalJson(['task_type' => $taskType, 'payload' => $payload]));
        return new OpsTaskSubmission(
            $taskType,
            $handlerKey,
            $payload,
            $idempotencyDigest,
            $requestDigest,
            $concurrencyKey,
            $maximumAttempts,
            new OpsAuditEvent($eventType, $action, [
                'provider_key' => $payload['provider_key'],
                'target_key' => $payload['target_key'] ?? null,
                'idempotency_digest' => $idempotencyDigest,
                'request_digest' => $requestDigest,
            ]),
        );
    }

    /** @param array<string,mixed> $task @return array<string,string> */
    private function payload(array $task): array
    {
        $payload = json_decode((string) $task['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        $expected = [
            'archive_sha256', 'confirm_plan_json', 'confirm_plan_sha256',
            'delivery_resource_id', 'environment', 'operation', 'package_key',
            'request_key', 'request_sha256', 'signature_key_id', 'source_commit',
            'source_tree', 'target_resource_id',
        ];
        if (is_array($payload) && $this->requests->isLocal($payload)) {
            $expected[] = 'application_root';
            sort($expected, SORT_STRING);
        }
        $keys = is_array($payload) ? array_keys($payload) : [];
        sort($keys, SORT_STRING);
        if ($keys !== $expected) {
            throw new \RuntimeException('OPS_MODULE_PAYLOAD_INVALID');
        }
        foreach ($payload as $value) {
            if (!is_string($value)) {
                throw new \RuntimeException('OPS_MODULE_PAYLOAD_INVALID');
            }
        }
        if (preg_match('/^modreq_[a-f0-9]{32}$/D', $payload['request_key']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payload['request_sha256']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $payload['source_commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $payload['source_tree']) !== 1
            || !in_array($payload['operation'], ['install', 'update', 'retire', 'purge'], true)
        ) {
            throw new \RuntimeException('OPS_MODULE_PAYLOAD_INVALID');
        }
        return $payload;
    }

    /** @param array<string,mixed> $task */
    private function context(array $task): PlatformContext
    {
        return PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(
                1,
                'module-worker-' . substr((string) $task['task_key'], 4, 16),
                (int) $task['account_id'],
                (int) $task['submitted_by_operator_id'],
                'platform-web',
                new DateTimeImmutable('now'),
            ),
            'module-' . substr((string) $task['task_key'], 4),
        );
    }

    /** @return array<string,mixed> */
    private function runningTask(string $taskKey, int $revision): array
    {
        $task = $this->taskQuery($taskKey, $revision)->find();
        if ($task === null) {
            throw new \RuntimeException('OPS_MODULE_EXECUTION_FENCED');
        }
        return $this->withOperatorAccount($task);
    }

    /** @return array<string,mixed> */
    private function lockedRunningTask(string $taskKey, int $revision): array
    {
        $task = $this->taskQuery($taskKey, $revision)->lock(true)->find();
        if ($task === null) {
            throw new \RuntimeException('OPS_MODULE_EXECUTION_FENCED');
        }
        return $this->withOperatorAccount($task);
    }

    /** @return array<string,mixed> */
    private function execution(string $taskKey, bool $forUpdate = false): array
    {
        $query = Db::name('ops_module_execution')->where('task_key', $taskKey);
        $row = ($forUpdate ? $query->lock(true) : $query)->find();
        if ($row === null) {
            throw new \RuntimeException('OPS_MODULE_EXECUTION_UNAVAILABLE');
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
            'module-' . substr((string) $task['task_key'], 4),
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
            ->where('task.task_key', $taskKey)->where('task.task_type', PlatformModuleOperationExecutionService::TASK_TYPE)
            ->where('task.status', 'running')->where('task.revision', $revision)->field('task.*');
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function withOperatorAccount(array $task): array
    {
        $task['account_id'] = $this->operators->accountId((int) $task['submitted_by_operator_id']);

        return $task;
    }

    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function transaction(callable $operation): mixed
    {
        return Db::transaction($operation);
    }
}
