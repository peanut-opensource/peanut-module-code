<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use PeanutAdmin\Modules\Ops\Infrastructure\ApplicationRuntimeStatusProvider;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpOpsTaskDispatcher;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Package;
use think\facade\Db;

/** Platform submission and read projection for the fixed PC42 upgrade workflow. */
final readonly class PlatformUpgradeExecutionService
{
    public const TASK_TYPE = 'ops.upgrade.execute';
    public const HANDLER_KEY = 'peanut.application.upgrade';
    public const CONCURRENCY_KEY = 'ops.upgrade.execute.production';
    public const PERMISSION = 'platform.ops.upgrade.manage';

    public function __construct(
        private ThinkPhpOpsTaskDispatcher $tasks,
        private string $projectRoot,
        private ApplicationRuntimeStatusProvider $runtimeStatus,
        private PlatformPermissionChecker $permissions,
    ) {}

    /** @return array<string,mixed> */
    public function submit(PlatformContext $context, string $idempotencyKey): array
    {
        if (!$this->permissions->allows($context, self::PERMISSION)
            || !$this->permissions->allows($context, Package::READ_PERMISSION)
        ) {
            throw OpsConsoleException::denied();
        }

        $readiness = $this->runtimeStatus
            ->upgradeReadiness($context);
        $sourceRuntime = is_array($readiness['source']['runtime'] ?? null)
            ? $readiness['source']['runtime']
            : null;
        $sourceApplication = is_array($readiness['source']['application'] ?? null)
            ? $readiness['source']['application']
            : null;
        $target = is_array($readiness['target'] ?? null) ? $readiness['target'] : null;
        if (($readiness['preflight']['state'] ?? null) !== 'ready'
            || $sourceRuntime === null || $sourceApplication === null || $target === null
        ) {
            throw OpsConsoleException::providerUnavailable();
        }

        $payload = [
            'source_commit' => $this->commit($sourceRuntime['commit'] ?? null),
            'source_tree' => $this->commit($sourceRuntime['tree'] ?? null),
            'source_release_key' => $this->releaseKey($sourceRuntime['release_key'] ?? null, true),
            'source_application_manifest_sha256' => $this->sha256(
                $sourceApplication['application_manifest_sha256'] ?? null,
            ),
            'target_release_key' => $this->releaseKey($target['release_key'] ?? null),
            'target_commit' => $this->commit($target['commit'] ?? null),
            'target_tree' => $this->commit($target['tree'] ?? null),
            'target_descriptor_sha256' => $this->sha256($target['descriptor_sha256'] ?? null),
        ];

        $row = $this->tasks
            ->dispatchUpgrade($context, $payload, $idempotencyKey);
        return $this->taskProjection($row);
    }

    /** @return array{tasks:list<array<string,mixed>>} */
    public function snapshot(PlatformContext $context): array
    {
        $this->assertRead($context);
        $tasks = array_map(
            fn(array $row): array => $this->taskProjection($row),
            Db::name('ops_task')->where('task_type', self::TASK_TYPE)->order('id', 'desc')->limit(10)->select()->toArray(),
        );
        return ['tasks' => $tasks];
    }

    /** @return array<string,mixed>|null */
    public function taskIfUpgrade(PlatformContext $context, string $taskKey): ?array
    {
        $this->assertRead($context);
        if (preg_match('/^job_[a-f0-9]{32}$/D', $taskKey) !== 1) {
            return null;
        }
        $row = Db::name('ops_task')->where('task_key', $taskKey)->where('task_type', self::TASK_TYPE)->find();
        return is_array($row) ? $this->taskProjection($row) : null;
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function taskProjection(array $task): array
    {
        $execution = $this->execution((string) $task['task_key']);
        $payload = json_decode((string) $task['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw OpsConsoleException::taskUnavailable();
        }
        $recoveryPointer = null;
        if (is_array($execution) && is_string($execution['recovery_pointer_json'] ?? null)) {
            $decoded = json_decode($execution['recovery_pointer_json'], true, 512, JSON_THROW_ON_ERROR);
            $recoveryPointer = is_array($decoded) ? $decoded : null;
        }

        return [
            'task_key' => (string) $task['task_key'],
            'task_type' => self::TASK_TYPE,
            'status' => (string) $task['status'],
            'attempt_count' => (int) $task['attempt_count'],
            'max_attempts' => (int) $task['max_attempts'],
            'revision' => (int) $task['revision'],
            'last_error_code' => $task['last_error_code'] === null ? null : (string) $task['last_error_code'],
            'current_step' => is_array($execution) ? (string) $execution['current_step'] : 'preflight',
            'source' => [
                'commit' => (string) $payload['source_commit'],
                'tree' => (string) $payload['source_tree'],
                'release_key' => $payload['source_release_key'] === '' ? null : (string) $payload['source_release_key'],
                'application_manifest_sha256' => (string) $payload['source_application_manifest_sha256'],
            ],
            'target' => [
                'release_key' => (string) $payload['target_release_key'],
                'commit' => (string) $payload['target_commit'],
                'tree' => (string) $payload['target_tree'],
                'descriptor_sha256' => (string) $payload['target_descriptor_sha256'],
            ],
            'backup_reference_key' => is_array($execution) && is_string($execution['backup_reference_key'] ?? null)
                ? $execution['backup_reference_key'] : null,
            'restore_evidence_sha256' => is_array($execution) && is_string($execution['restore_evidence_sha256'] ?? null)
                ? $execution['restore_evidence_sha256'] : null,
            'maintenance_key' => is_array($execution) && is_string($execution['maintenance_key'] ?? null)
                ? $execution['maintenance_key'] : null,
            'recovery_pointer' => $recoveryPointer,
            'recovery_pointer_sha256' => is_array($execution) && is_string($execution['recovery_pointer_sha256'] ?? null)
                ? $execution['recovery_pointer_sha256'] : null,
            'steps' => $this->steps((string) $task['task_key']),
            'available_at' => $this->instant((string) $task['available_at']),
            'created_at' => $this->instant((string) $task['created_at']),
            'updated_at' => $this->instant((string) $task['updated_at']),
            'completed_at' => $task['completed_at'] === null ? null : $this->instant((string) $task['completed_at']),
        ];
    }

    /** @return array<string,mixed>|null */
    private function execution(string $taskKey): ?array
    {
        $row = Db::name('ops_upgrade_execution')->where('task_key', $taskKey)->find();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function steps(string $taskKey): array
    {
        $steps = [];
        $rows = Db::name('ops_upgrade_step')->where('task_key', $taskKey)
            ->field('step_key,step_order,status,input_sha256,output_sha256,last_error_code,started_at,completed_at')
            ->order('step_order')->select()->toArray();
        foreach ($rows as $row) {
            $steps[] = [
                'step_key' => (string) $row['step_key'],
                'step_order' => (int) $row['step_order'],
                'status' => (string) $row['status'],
                'input_sha256' => (string) $row['input_sha256'],
                'output_sha256' => $row['output_sha256'] === null ? null : (string) $row['output_sha256'],
                'last_error_code' => $row['last_error_code'] === null ? null : (string) $row['last_error_code'],
                'started_at' => $row['started_at'] === null ? null : $this->instant((string) $row['started_at']),
                'completed_at' => $row['completed_at'] === null ? null : $this->instant((string) $row['completed_at']),
            ];
        }
        return $steps;
    }

    private function assertRead(PlatformContext $context): void
    {
        if (!$this->permissions->allows($context, Package::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
    }

    private function commit(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}$/D', $value) !== 1) {
            throw OpsConsoleException::providerUnavailable();
        }
        return $value;
    }

    private function sha256(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw OpsConsoleException::providerUnavailable();
        }
        return $value;
    }

    private function releaseKey(mixed $value, bool $nullable = false): string
    {
        if ($nullable && $value === null) {
            return '';
        }
        if (!is_string($value) || preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $value) !== 1) {
            throw OpsConsoleException::providerUnavailable();
        }
        return $value;
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
