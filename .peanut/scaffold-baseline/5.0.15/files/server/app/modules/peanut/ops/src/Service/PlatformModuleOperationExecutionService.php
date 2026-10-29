<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use PeanutAdmin\Modules\Ops\Infrastructure\ApplicationRuntimeStatusProvider;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpOpsTaskDispatcher;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Package;
use Closure;
use think\facade\Db;

/** Platform projection for opaque deployment-staged Module requests. */
final readonly class PlatformModuleOperationExecutionService
{
    public const TASK_TYPE = 'ops.module.execute';
    public const HANDLER_KEY = 'peanut.module.delivery';
    public const CONCURRENCY_KEY = 'ops.module.execute.production';
    public const PERMISSION = 'platform.ops.module.manage';

    public function __construct(
        private ThinkPhpOpsTaskDispatcher $tasks,
        private DeploymentModuleRequestService $requests,
        private ApplicationRuntimeStatusProvider|Closure $runtimeStatus,
        private PlatformPermissionChecker $permissions,
    ) {}

    /** @return array<string,mixed> */
    public function submit(PlatformContext $context, string $requestKey, string $idempotencyKey): array
    {
        return Db::transaction(function () use ($context, $requestKey, $idempotencyKey): array {
            $request = $this->requestStore()->assertPrepared($requestKey);
            $local = $this->requests->isLocal($request);
            if ($local) {
                if (!in_array($request['operation'], ['install', 'update'], true)
                    || !$this->permissions->allows($context, 'platform.module.' . $request['operation'])) {
                    throw OpsConsoleException::denied();
                }
            } elseif (!$this->permissions->allows($context, self::PERMISSION)
                || !$this->permissions->allows($context, Package::READ_PERMISSION)) {
                throw OpsConsoleException::denied();
            }
            // Each immutable HTTP request includes its operator-scoped submission identity.
            // Retrying that submission returns its receipt; a new submission reaches the existing concurrency fence.
            $idempotent = Db::name('ops_task')->where('submitted_by_operator_id', $context->operatorId)
                ->where('idempotency_digest', hash('sha256', $idempotencyKey))->lock(true)->find();
            if (is_array($idempotent)) {
                $previous = json_decode((string) $idempotent['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                if ($idempotent['task_type'] !== self::TASK_TYPE || ($previous['request_key'] ?? null) !== $requestKey) {
                    throw OpsConsoleException::idempotencyConflict();
                }
            }
            $existing = Db::name('ops_task')->where('task_type', self::TASK_TYPE)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.request_key')) = ?", [$requestKey])
                ->order('id', 'desc')->lock(true)->find();
            if (is_array($existing)) {
                return $this->taskProjection($existing);
            }
            $runtime = $this->runtime($context);
            if ($runtime['health'] === 'unhealthy' || (!$local && !$runtime['repository_clean'])
                || preg_match('/^[a-f0-9]{40}$/D', $runtime['commit']) !== 1
                || preg_match('/^[a-f0-9]{40}$/D', $runtime['tree']) !== 1
            ) {
                throw OpsConsoleException::providerUnavailable();
            }
            $payload = [
                'request_key' => (string) $request['request_key'],
                'request_sha256' => (string) $request['request_sha256'],
                'environment' => (string) $request['environment'],
                'target_resource_id' => (string) $request['target_resource_id'],
                'delivery_resource_id' => (string) $request['delivery_resource_id'],
                'operation' => (string) $request['operation'],
                'package_key' => (string) $request['package_key'],
                'archive_sha256' => $request['archive_sha256'] === null ? '' : (string) $request['archive_sha256'],
                'signature_key_id' => $request['signature_key_id'] === null ? '' : (string) $request['signature_key_id'],
                'confirm_plan_json' => $request['confirm_plan_json'] === null ? '' : (string) $request['confirm_plan_json'],
                'confirm_plan_sha256' => $request['confirm_plan_sha256'] === null ? '' : (string) $request['confirm_plan_sha256'],
                'source_commit' => $runtime['commit'],
                'source_tree' => $runtime['tree'],
            ];
            if ($local) {
                $payload['application_root'] = $this->requests->applicationRoot();
            }
            $row = $this->tasks
                ->dispatchModuleOperation($context, $payload, $idempotencyKey);
            Db::name('ops_module_request')->where('request_key', $requestKey)->where('state', 'prepared')
                ->update(['state' => 'claimed', 'claimed_at' => Db::raw('UTC_TIMESTAMP(3)')]);
            return $this->taskProjection($row);
        });
    }

    /** @return array<string,mixed>|null */
    public function taskIfModuleOperation(PlatformContext $context, string $taskKey): ?array
    {
        $this->assertRead($context);
        if (preg_match('/^job_[a-f0-9]{32}$/D', $taskKey) !== 1) {
            return null;
        }
        $row = Db::name('ops_task')->where('task_key', $taskKey)->where('task_type', self::TASK_TYPE)->find();
        return is_array($row) ? $this->taskProjection($row) : null;
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

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function taskProjection(array $task): array
    {
        $payload = json_decode((string) $task['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw OpsConsoleException::taskUnavailable();
        }
        $execution = Db::name('ops_module_execution')->where('task_key', $task['task_key'])->find();
        $pointer = null;
        if (is_array($execution) && is_string($execution['recovery_pointer_json'] ?? null)) {
            $decoded = json_decode($execution['recovery_pointer_json'], true, 64, JSON_THROW_ON_ERROR);
            $pointer = is_array($decoded) ? $decoded : null;
        }
        return [
            'task_key' => (string) $task['task_key'],
            'task_type' => self::TASK_TYPE,
            'status' => (string) $task['status'],
            'attempt_count' => (int) $task['attempt_count'],
            'revision' => (int) $task['revision'],
            'last_error_code' => $task['last_error_code'] === null ? null : (string) $task['last_error_code'],
            'request_key' => (string) $payload['request_key'],
            'environment' => (string) $payload['environment'],
            'target_resource_id' => (string) $payload['target_resource_id'],
            'operation' => (string) $payload['operation'],
            'package_key' => (string) $payload['package_key'],
            'current_step' => is_array($execution) ? (string) $execution['current_step'] : 'preflight',
            'result' => is_array($execution) && is_string($execution['operation_result_json'] ?? null)
                ? json_decode($execution['operation_result_json'], true, 128, JSON_THROW_ON_ERROR) : null,
            'backup_reference_key' => is_array($execution) ? $execution['backup_reference_key'] : null,
            'restore_evidence_sha256' => is_array($execution) ? $execution['restore_evidence_sha256'] : null,
            'maintenance_key' => is_array($execution) ? $execution['maintenance_key'] : null,
            'recovery_pointer' => $pointer,
            'recovery_pointer_sha256' => is_array($execution) ? $execution['recovery_pointer_sha256'] : null,
            'created_at' => $this->instant((string) $task['created_at']),
            'updated_at' => $this->instant((string) $task['updated_at']),
            'completed_at' => $task['completed_at'] === null ? null : $this->instant((string) $task['completed_at']),
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

    private function assertRead(PlatformContext $context): void
    {
        if (!$this->permissions->allows($context, Package::READ_PERMISSION)
            && !$this->permissions->allows($context, 'platform.module.read')) {
            throw OpsConsoleException::denied();
        }
    }

    private function instant(string $value): string
    {
        $normalized = str_replace(' ', 'T', trim($value));
        return $normalized . (str_contains($normalized, '.') ? 'Z' : '.000Z');
    }
}
